<?php
// define the old-style EE object
if (!function_exists('ee')) {
  function ee() {
    static $EE;
    if (! $EE) {
      $EE = get_instance();
    }
    return $EE;
  }
}

$plugin_info = array(
  'pi_name' => 'Alpha_list',
  'pi_version' =>'0.1',
  'pi_author' =>'Andy Hebrank',
  'pi_author_url' => 'http://insidenewcity.com/',
  'pi_description' => 'Custom alphabetical list functions',
  'pi_usage' => Alpha_list::usage()
  );

class Alpha_list {

  // columns that might be looked at in the channel_titles table
  var $channel_title_fields = array('title');
  // well, you know
  var $alphabet = array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z');
  
  var $entry_lookup = array();
  var $start_letter = 'A';
  var $soft_limit = 10;

  /**
   * make an entry_id => field value lookup
   */
  function __construct() {
    // always used
    $channel_id = ee()->TMPL->fetch_param('channel', null);
    $letter_field = ee()->TMPL->fetch_param('field_name', 'title');

    // optional
    $this->soft_limit = ee()->TMPL->fetch_param('soft_limit', 10);
    $this->start_letter = strtoupper(ee()->TMPL->fetch_param('start_letter', 'A'));

    // special case to show everything
    if ($this->start_letter == "ALL") {
      $this->start_letter = 'A';
      $this->soft_limit = -1;
    }

    // lookup the channel name, if it's not a number
    if (!is_null($channel_id) && !is_numeric($channel_id)) {
      $channel_id = $this->_get_channel_id($channel_id);
      if ($channel_id === false) {
        $this->return_data = "Channel not found.";
        return $this->return_data;
      }
    }

    $filtering = ee()->TMPL->fetch_param('use_filters', 'no');
    $filters = null;
    if ($filtering == "yes") {
      $valid_filters = $this->_get_valid_filters($channel_id, $_GET);
      if (!empty($valid_filters)) {
        $filters = array_combine($this->_get_field_ids(array_keys($valid_filters)), $valid_filters);
        // clean out empty filters
        foreach ($filters as $f => $v) {
          if (empty($v)) {
            unset($filters[$f]);
          }
        }
        // now split between regular filters and relationships
        $relationship_filters = $this->_get_relationship_filters($filters);
        if (!empty($relationship_filters)) {
          $filters = array_diff($filters, $relationship_filters);
          $relationship_parents = $this->_get_relationship_matches($relationship_filters);
          if (empty($relationship_parents)) {
            // nothing found with these relationship criteria!
            $this->entry_lookup = array();
            return;
          }
        }

        // switch to fuzzy matching
        foreach ($filters as $f => $v) {
          unset($filters[$f]);
          $filters[$f . " LIKE"] = "%".$v."%";
        }
      }
    }

    // using a channel_title field or channel_data field?
    // make an entry_id => relevant field value lookup
    $select = array('channel_titles.entry_id AS entry_id');
    if (in_array($letter_field, $this->channel_title_fields)) {
      $select[] = $letter_field . " AS val";
    }
    else {
      // find the field id
      $field_id = $this->_get_field_id($letter_field);
      if ($field_id === false) {
        $this->return_data = "Field not found.";
        return $this->return_data;
      }
      $select[] = $field_id . " AS val";
    }

    $result = ee()->db->select($select)
        ->from('channel_titles')
        ->join('channel_data', 'channel_titles.entry_id = channel_data.entry_id');

    if (!is_null($channel_id)) {
      $result = $result->where('channel_titles.channel_id', $channel_id);
    }
    if (!empty($filters)) {
      $result = $result->where($filters);
    }
    if (!empty($relationship_filters)) {
      $result = $result->where_in('channel_titles.entry_id', $relationship_parents);
    }
    $result = $result->order_by('val asc')->get()->result();

    $this->entry_lookup = $result;
  }

  /**
   * generate a list of IDs for subsequent use in an exp:channel:entries
   * no distinction between letters
   */
  function entry_ids() {

    // filter the lookup
    // can't use a clean filter since there's a soft limit on the count
    $count = 0;
    $filtered = array();
    $started = false;
    $current_letter = '';
    foreach ($this->entry_lookup as $row) {
      $starts_with = $this->_starts_with($row->val);
      if ($starts_with == $this->start_letter) {
        $started = true;
      }
      if ($started) {
        if (($count < $this->soft_limit) || ($this->soft_limit == -1)) {
          // no problem
          $filtered[] = $row->entry_id;
        }
        else {
          // over the limit, but still on the same letter
          if ($starts_with == $current_letter) {
            $filtered[] = $row->entry_id;
          }
          else {
            break;
          }
        }
      }
      $current_letter = $starts_with;
    }

    $this->return_data = implode('|', $filtered);
    return $this->return_data;
  }

  /**
   * return a list of links to letters, with inactive links for letters without entries
   */
  function letter_list() {
    // url base
    $link_base = ee()->TMPL->fetch_param('url_root', "");
    // append a query string?
    $include_query = ee()->TMPL->fetch_param('include_query', "no");
    $query_string = ($include_query == "yes")? "?" . $_SERVER['QUERY_STRING'] : "";

    $output = '<ul class="alpha-letters">';
    foreach ($this->alphabet as $letter) {
      $output .= "\n"."  <li>";
      if ($this->_letter_count($letter)) {
        // linkable
        $output .= '<a href="' . $link_base . $letter . $query_string . '">' . $letter . "</a>";
      }
      else {
        // just a label
        $output .= '<span>' . $letter . "</span>";
      }
      $output .= "</li>";
    }
    $output .= "\n</ul>";

    $this->return_data = $output;
    return $this->return_data;
  }

  /**
   * return letters with items in a loop
   * this is called as a tag pair, looping through the alphabet
   * populates tags {letter} and {entry_ids} (a piped list of entries)
   */
  function letters() {
    $tagdata = ee()->TMPL->tagdata;
    $output = "";

    // slice the alphabet by starting letter
    $i = array_search($this->start_letter, $this->alphabet);
    $sliced_alphabet = $this->alphabet;
    if ($i !== false) {
      $sliced_alphabet = array_slice($this->alphabet, $i);
    }

    // loop each letter until passing the soft limit
    $total = 0;
    foreach ($sliced_alphabet as $letter) {
      $entries = $this->_entry_ids_for_letter($letter);
      $letter_count = count($entries);
      $total += $letter_count;
      if ($letter_count) {
        $data = array(
          'letter' => $letter,
          'entry_ids' => implode("|", $entries));
        $output .= ee()->TMPL->parse_variables_row($tagdata, $data);
      }

      // has this letter put us over the limit?
      if (($this->soft_limit != -1) && ($total > $this->soft_limit)) {
        break;
      }
    }

    $this->return_data = $output;
    return $this->return_data;
  }

  /**
   * return a field id, if given a field name
   */
  private function _get_field_id($field_name) {
    if (in_array($field_name, $this->channel_title_fields)) {
      return $field_name;
    }
    $result = ee()->db->select('field_id')
      ->from('channel_fields')
      ->where('field_name', $field_name)
      ->get();
    if ($result->num_rows()==0) {
      return false;
    }
    $result = $result->row();
    return "field_id_" . $result->field_id;
  }

  /**
   * the array version of _get_field_id
   */
  private function _get_field_ids($field_names) {
    return array_map(array($this, '_get_field_id'), $field_names);
  }

  /**
   * return a channel_id for a channel name
   */
  private function _get_channeL_id($channel_name) {
    $result = ee()->db->select('channel_id')
      ->from('channels')
      ->where('channel_name', $channel_name)
      ->get();
    if ($result->num_rows()==0) {
      return false;
    }
    $result = $result->row();
    return $result->channel_id;
  }

  /**
   * return a capitalized starting letter
   */
  private function _starts_with($val) {
    return strtoupper(substr($val, 0, 1));
  }

  /**
   * number of entries for a letter
   */
  private function _letter_count($letter) {
    $filtered = $this->_entry_ids_for_letter($letter);
    return (count($filtered));
  }

  /**
   * return all entry IDs for a letter
   */
  private function _entry_ids_for_letter($letter) {
    // array_filter would be nice, but can't rely on closures being available
    $filtered = array();
    foreach ($this->entry_lookup as $row) {
      if ($this->_starts_with($row->val) == $letter) {
        $filtered[] = $row->entry_id;
      }
    }
    return $filtered;
  }

  /**
   * return a list of valid items to filter by
   * these include the title fields and any fields in the channel
   */
  private function _get_valid_filters($channel_id, $filters) {
    $allowed = $this->channel_title_fields;

    // get all field names associated with this channel
    // find the field group
    if (!is_null($channel_id)) {
      $result = ee()->db->select('field_group')
        ->from('channels')
        ->where('channel_id', $channel_id)
        ->get();
      if ($result->num_rows()==0) {
        return array_intersect_key($filters, array_flip($allowed));
      }
      $result = $result->row();
      $field_group = $result->field_group;
    }

    // find the fieldnames in this group
    $result = ee()->db->select('field_name')
      ->from('channel_fields');
    if (!is_null($channel_id)) {
      $result = $result->where('group_id', $field_group);
    }
    $result = $result->get();
    if ($result->num_rows()==0) {
      return array_intersect_key($filters, array_flip($allowed));
    }
    foreach ($result->result() as $row) {
      $allowed[] = $row->field_name;
    }
    $allowed = array_flip($allowed);

    return array_intersect_key($filters, $allowed);
  }

  /**
   * figure out which filter fields are relationships
   */
  private function _get_relationship_filters($filters) {
    if (empty($filters)) {
      return null;
    }
    $field_ids = array_keys($filters);
    // since these are now "field_id_N", need to make them back into just numbers
    $ids = array();
    foreach ($field_ids as $f) {
      $ids[] = str_replace("field_id_", "", $f);
    }

    $result = ee()->db->select('field_id')
      ->from('channel_fields')
      ->where_in('field_id', $ids)
      ->where('field_type', 'relationship')
      ->get();

    if ($result->num_rows() == 0) {
      return null;
    }

    $relationship_field_ids = array();
    foreach ($result->result() as $row) {
      $relationship_field_ids[] = "field_id_" . $row->field_id;
    }

    return array_intersect_key($filters, array_flip($relationship_field_ids));
  }

  /**
   * find matches for relationship filters
   */
  private function _get_relationship_matches($filters) {
    $parents = array();
    $ids = array();
    foreach ($filters as $field_id => $val) {
      $id = str_replace("field_id_", "", $field_id);

      // find all matching parents for this field and child value
      $result = ee()->db->select('relationships.parent_id AS entry_id')
        ->from('relationships')
        ->join('channel_titles', 'relationships.child_id = channel_titles.entry_id')
        ->where('relationships.field_id', $id);
      if (is_numeric($val)) {
        $result = $result->where('relationships.child_id', $val);
      } 
      else {
        // assume it's the url_title
        $result = $result->where('channel_titles.url_title', $val);
      }
      $result = $result->get();
      if ($result->num_rows() == 0) {
        return array();
      }

      $this_field_ids = array();
      foreach ($result->result() as $row) {
        $this_field_ids[] = $row->entry_id;
      }

      if (empty($ids)) {
        $ids = $this_field_ids;
      }
      else {
        $ids = array_intersect($ids, $this_field_ids);
      }
    }

    return $ids;
  }

  function usage() {
    ob_start();
?>
{exp:relate_entries channel="staff" start_letter="A" field_name="staff_last_name" soft_limit="10"}
<?php
    return ob_get_clean();
  }

}