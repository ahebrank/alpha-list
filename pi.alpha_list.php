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
    $this->start_letter = strtoupper(ee()->TMPL->fetch_param('start_letter', 'A'));
    $this->soft_limit = ee()->TMPL->fetch_param('soft_limit', 10);

    // lookup the channel name, if it's not a number
    if (!is_null($channel_id) && !is_numeric($channel_id)) {
      $channel_id = $this->_get_channel_id($channel_id);
      if ($channel_id === false) {
        $this->return_data = "Channel not found.";
        return $this->return_data;
      }
    }

    // using a channel_title field or channel_data field?
    // make an entry_id => relevant field value lookup
    if (in_array($letter_field, $this->channel_title_fields)) {
      $result = ee()->db->select(array('entry_id', $letter_field . " AS val"))
        ->from('channel_titles');
    }
    else {
      // find the field id
      $field_id = $this->_get_field_id($letter_field);
      if ($field_id === false) {
        $this->return_data = "Field not found.";
        return $this->return_data;
      }
      $result = ee()->db->select(array('entry_id', $field_id . " AS val"))
        ->from('channel_data');
    }

    if (!is_null($channel_id)) {
      $result = $result->where('channel_id', $channel_id);
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
        if ($count < $this->soft_limit) {
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
    $link_base = ee()->TMPL->fetch_param('url_root', "");

    $output = '<ul class="alpha-letters">';
    foreach ($this->alphabet as $letter) {
      $output .= "\n"."  <li>";
      if ($this->_letter_count($letter)) {
        // linkable
        $output .= '<a href="' . $link_base . $letter.'">' . $letter . "</a>";
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
      if ($total > $this->soft_limit) {
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

  function usage() {
    ob_start();
?>
{exp:relate_entries channel="staff" start_letter="A" field_name="staff_last_name" soft_limit="10"}
<?php
    return ob_get_clean();
  }

}