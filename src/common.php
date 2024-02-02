<?php
ini_set( 'display_errors', '0' );
error_reporting( E_ALL | E_STRICT );
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );

// function for writing to the log
function output( $message )
{
  printf( "%s> %s\n", date( 'Y-m-d (D) H:i:s' ), $message );
}

function fatal_error( $message, $code )
{
  output( sprintf( 'ERROR: %s', $message ) );
  exit( $code );
}

function get_cenozo_db()
{
  return new \mysqli( CENOZO_DB_HOSTNAME, CENOZO_DB_USERNAME, CENOZO_DB_PASSWORD, CENOZO_DB_DATABASE );
}

function check_directories()
{
  // Make sure the destination directories exist
  $test_dir_list = array(
    DATA_DIR,
    sprintf( '%s/%s', DATA_DIR, TEMPORARY_DIR ),
    sprintf( '%s/%s', DATA_DIR, INVALID_DIR )
  );
  foreach( $test_dir_list as $dir )
  {
    if( !is_dir( $dir ) ) fatal_error( sprintf( 'Expected directory, "%s", not found', $dir ), 1 );
    if( !TEST_ONLY && !is_writable( $dir ) ) fatal_error( sprintf( 'Cannot write to directory "%s"', $dir ), 2 );
  }
}

function format_filename( $filename )
{
  return sprintf( '"%s"', str_replace( '"', '\"', $filename ) );
}

function move_from_temporary_to_invalid( $file_or_dir, $reason = NULL )
{
  if( VERBOSE && !is_null( $reason ) ) output( $reason );
  if( TEST_ONLY || KEEP_FILES ) return;

  // make sure the parent directory exists
  $rename_from = $file_or_dir;
  $rename_to = preg_replace(
    sprintf( '#/%s/#', TEMPORARY_DIR ),
    sprintf( '/%s/', INVALID_DIR ),
    $file_or_dir
  );
  $destination_dir = preg_replace( '#/[^/]+$#', '', $rename_to );

  // make sure the destination directory exists
  if( !is_dir( $destination_dir ) ) mkdir( $destination_dir, 0755, true );

  // if the rename_to file or dir already exists then delete it
  if( file_exists( $rename_to ) ) exec( sprintf( 'rm -rf %s', format_filename( $rename_to ) ) );

  // move the file
  rename( $rename_from, $rename_to );
  // can't do this natively since there is no -R option
  exec( sprintf( 'chgrp -R sftpusers %s', format_filename( $rename_to ) ) );

  // write the reason to a file in the temporary directory
  if( !is_null( $reason ) )
  {
    $reason_filename = sprintf( is_dir( $rename_to ) ? '%s/error.txt' : '%s.error.txt', $rename_to );
    file_put_contents( $reason_filename, sprintf( "%s\n", $reason ) );
    chgrp( $reason_filename, 'sftpusers' );
  }
}

// find and remove all empty directories
function remove_dir( $dir )
{
  // first remove all empty sub directories
  foreach( glob( sprintf( '%s/*', $dir ), GLOB_ONLYDIR ) as $subdir ) remove_dir( $subdir );

  // now see if the directory is empty and remove it is it is
  if( 0 == count( glob( sprintf( '%s/*', $dir ) ) ) ) rmdir( $dir );
}

// Reads the id_lookup file and returns an array containing Study ID => UID pairs
function get_study_uid_lookup( $identifier_name, $events = false, $consents = false )
{
  $cenozo_db = get_cenozo_db();

  $select_list = ['participant.uid', 'participant_identifier.value'];
  if( $events )
  {
    // create a temporary table for home and site events
    $cenozo_db->query( 'SELECT id INTO @home_id FROM event_type WHERE name = "completed (Follow-Up 3 Home)"' );
    $cenozo_db->query( 'DROP TABLE IF EXISTS home_event' );
    $cenozo_db->query(
      'CREATE TEMPORARY TABLE home_event '.
      'SELECT '.
        'participant.id AS participant_id, '.
        'DATE( CONVERT_TZ( event.datetime, "UTC", "Canada/Eastern" ) ) AS date '.
      'FROM participant '.
      'JOIN participant_last_event AS ple '.
        'ON participant.id = ple.participant_id '.
        'AND ple.event_type_id = @home_id '.
      'JOIN event AS event '.
        'ON ple.event_id = event.id'
    );
    $cenozo_db->query( 'ALTER TABLE home_event ADD PRIMARY KEY (participant_id)' );

    $cenozo_db->query( 'SELECT id INTO @site_id FROM event_type WHERE name = "completed (Follow-Up 3 Site)"' );
    $cenozo_db->query( 'DROP TABLE IF EXISTS site_event' );
    $cenozo_db->query(
      'CREATE TEMPORARY TABLE site_event '.
      'SELECT '.
        'participant.id AS participant_id, '.
        'DATE( CONVERT_TZ( event.datetime, "UTC", "Canada/Eastern" ) ) AS date '.
      'FROM participant '.
      'JOIN participant_last_event AS ple '.
        'ON participant.id = ple.participant_id '.
       'AND ple.event_type_id = @site_id '.
      'JOIN event AS event '.
        'ON ple.event_id = event.id'
    );
    $cenozo_db->query( 'ALTER TABLE site_event ADD PRIMARY KEY (participant_id)' );

    $select_list[] = 'home_event.date AS home_date';
    $select_list[] = 'site_event.date AS site_date';
  }
  if( $consents )
  {
    $select_list[] = 'IFNULL( sleep_consent.accept, false ) AS sleep_consent';
    $select_list[] = 'IFNULL( mobility_consent.accept, false ) AS mobility_consent';
  }

  $sql = sprintf(
    'SELECT %s '.
    'FROM participant '.
    'JOIN identifier '.
    'JOIN participant_identifier '.
      'ON identifier.id = participant_identifier.identifier_id '.
      'AND participant.id = participant_identifier.participant_id ',
    implode( ', ', $select_list )
  );

  if( $events )
  {
    $sql .=
      'LEFT JOIN home_event ON participant.id = home_event.participant_id '.
      'LEFT JOIN site_event ON participant.id = site_event.participant_id ';
  }

  if( $consents )
  {
    $sql .=
      'JOIN participant_last_consent AS sleep_plc ON participant.id = sleep_plc.participant_id '.
      'JOIN consent_type AS sleep_consent_type ON sleep_plc.consent_type_id = sleep_consent_type.id '.
        'AND sleep_consent_type.name = "F3 Sleep Trackers" '.
      'LEFT JOIN consent AS sleep_consent ON sleep_plc.consent_id = sleep_consent.id '.
      'JOIN participant_last_consent AS mobility_plc ON participant.id = mobility_plc.participant_id '.
      'JOIN consent_type AS mobility_consent_type ON mobility_plc.consent_type_id = mobility_consent_type.id '.
        'AND mobility_consent_type.name = "F3 Mobility Trackers" '.
      'LEFT JOIN consent AS mobility_consent ON mobility_plc.consent_id = mobility_consent.id ';
  }

  $sql .= sprintf( 'WHERE identifier.name = "%s"', $cenozo_db->real_escape_string( $identifier_name ) );

  $result = $cenozo_db->query( $sql );
  $cenozo_db->query( 'DROP TABLE IF EXISTS home_event' );
  $cenozo_db->query( 'DROP TABLE IF EXISTS site_event' );
  $cenozo_db->close();

  if( false === $result ) throw new Exception( 'Unable to get study UID lookup data.' );

  $data = [];
  while( $row = $result->fetch_assoc() ) $data[$row['value']] = ( $events || $consents ) ? $row : $row['uid'];
  $result->free();

  return $data;
}

/**
 * Sends a curl request to the opal server(s)
 * 
 * @param array(key->value) $arguments The url arguments as key->value pairs (value may be null)
 * @param boolean $alternate Whether to use the alternate Opal server (updated daily instead of weekly)
 * @return curl resource
 */
function opal_send( $arguments, $alternate = false )
{
  $curl = curl_init();

  $code = 0;
  
  $user = $alternate ? OPALALT_USERNAME : OPAL_USERNAME;
  $pass = $alternate ? OPALALT_PASSWORD : OPAL_PASSWORD;
  $url = $alternate ? OPALALT_URL : OPAL_URL;
  $timeout = $alternate ? OPALALT_TIMEOUT : OPAL_TIMEOUT;

  // prepare cURL request
  $headers = array(
    sprintf( 'Authorization: X-Opal-Auth %s', base64_encode( sprintf( '%s:%s', $user, $pass ) ) ),
    'Accept: application/json'
  );

  $postfix = array();
  foreach( $arguments as $key => $value )
  {
    if( in_array( $key, array( 'counts', 'offset', 'limit', 'pos', 'select' ) ) )
      $postfix[] = sprintf( '%s=%s', $key, $value );
    else $url .= is_null( $value ) ? sprintf( '/%s', $key ) : sprintf( '/%s/%s', $key, rawurlencode( $value ) );
  }

  if( 0 < count( $postfix ) ) $url .= sprintf( '?%s', implode( '&', $postfix ) );

  // set URL and other appropriate options
  curl_setopt( $curl, CURLOPT_URL, $url );
  curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, false );
  curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
  curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT, $timeout );
  curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );

  $response = curl_exec( $curl );
  $code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );

  if( array_key_exists( 'valueSet', $arguments ) && 404 == $code )
  {
    // ignore 404 and set response to null
    $response = NULL;
  }
  else if( 200 != $code )
  {
    throw new \Exception( sprintf(
      'Unable to connect to Opal service for url "%s" (code: %s)',
      $url,
      $code
    ) );
  }

  return $response;
}


/**
 * Processes all actigraph files
 * 
 * @param string $identifier_name The name of the identifier used in actigraph filenames
 * @param string $study The name of the study that files come from
 * @param integer $phase The phase of the study that files come from
 */
function process_actigraph_files( $identifier_name, $study, $phase )
{
  $base_dir = sprintf( '%s/%s', DATA_DIR, TEMPORARY_DIR );
  $study_uid_lookup = get_study_uid_lookup( $identifier_name, true, true ); // include event and consent data

  // Each site has their own directory, and in each site directory there are sub-directories for
  // each modality (actigraph, ticwatch, etc).  Within the actigraph directory there is one file
  // per participant named after the participant's study_id and the date of the data:
  // For example: "temporary/XXX/actigraph/<study_id> <date>.gt3x"
  output( sprintf( 'Processing actigraph files in "%s"', $base_dir ) );
  $file_count = 0;
  foreach( glob( sprintf( '%s/[A-Z][A-Z][A-Z]/actigraph/*', $base_dir ) ) as $filename )
  {
    $preg = '#/([^/]+) \(([0-9]{4}-[0-9]{2}-[0-9]{2})\)(_thigh|_wrist)?\.gt3x$#';
    $matches = [];
    if( !preg_match( $preg, $filename, $matches ) )
    {
      $reason = sprintf(
        'Cannot transfer actigraph file, "%s", invalid format.',
        $filename
      );
      move_from_temporary_to_invalid( $filename, $reason );
      continue;
    }

    $study_id = strtoupper( trim( $matches[1] ) );
    $date = str_replace( '-', '', $matches[2] );
    $type = 4 <= count( $matches ) ? trim( $matches[3], '_' ) : 'unknown';

    if( !array_key_exists( $study_id, $study_uid_lookup ) )
    {
      $reason = sprintf(
        'Cannot transfer actigraph data due to missing UID lookup for study ID "%s"',
        $study_id
      );
      move_from_temporary_to_invalid( $filename, $reason );
      continue;
    }
    $uid = $study_uid_lookup[$study_id]['uid'];
    $sleep_consent = $study_uid_lookup[$study_id]['sleep_consent'];
    $mobility_consent = $study_uid_lookup[$study_id]['mobility_consent'];
    $home_date = $study_uid_lookup[$study_id]['home_date'];
    $site_date = $study_uid_lookup[$study_id]['site_date'];

    // if the thigh/wrist type wasn't in the filename, look in the file's data instead
    if( 'unknown' == $type )
    {
      $file = file_get_contents( $filename );
      $type = 'unknown';
      if( $file )
      {
        if( preg_match( '/"Limb":"Thigh"/', $file ) ) $type = 'thigh';
        else if( preg_match( '/"Limb":"Wrist"/', $file ) ) $type = 'wrist';
      }
    }

    if( 'wrist' == $type )
    {
      // make sure the participant has consented to sleep trackers
      if( !$sleep_consent )
      {
        $reason = sprintf(
          'Wrist actigraph data without sleep consent, "%s".',
          $filename
        );
        move_from_temporary_to_invalid( $filename, $reason );
        continue;
      }
    }
    else if( 'thigh' == $type )
    {
      // make sure the participant has consented to mobility trackers
      if( !$mobility_consent )
      {
        $reason = sprintf(
          'Thigh actigraph data without mobility consent, "%s".',
          $filename
        );
        move_from_temporary_to_invalid( $filename, $reason );
        continue;
      }
    }
    else
    {
      $reason = sprintf(
        'No limb defined in actigraph file, "%s".',
        $filename
      );
      move_from_temporary_to_invalid( $filename, $reason );
      continue;
    }

    // make sure the date aligns with the participant's events
    $date_object = new DateTime( $date );
    $diff = NULL;

    // the thigh is done after the home interview
    if( 'thigh' == $type && $home_date ) $diff = $date_object->diff( new DateTime( $home_date ) );
    // the wrist is done after the site interview
    else if( 'wrist' == $type && $site_date ) $diff = $date_object->diff( new DateTime( $site_date ) );

    // only allow up to two days before or after
    if( is_null( $diff ) || 2 < $diff->days )
    {
      $reason = sprintf(
        'Invalid date found in %s actigraph file, "%s".',
        $type,
        $filename
      );
      move_from_temporary_to_invalid( $filename, $reason );
      continue;
    }

    $destination_directory = sprintf(
      '%s/raw/%s/%s/actigraph/%s',
      DATA_DIR,
      $study,
      $phase,
      $uid
    );

    // make sure the directory exists (recursively)
    if( !TEST_ONLY && !is_dir( $destination_directory ) ) mkdir( $destination_directory, 0755, true );

    $destination = sprintf( '%s/%s_%s.gt3x', $destination_directory, $type, $date );
    $copy = TEST_ONLY ? true : copy( $filename, $destination );
    if( $copy )
    {
      if( VERBOSE ) output( sprintf( '"%s" => "%s"', $filename, $destination ) );
      if( !TEST_ONLY && !KEEP_FILES ) unlink( $filename );
      $file_count++;
    }
    else
    {
      $reason = sprintf(
        'Failed to copy "%s" to "%s"',
        $filename,
        $destination
      );
      move_from_temporary_to_invalid( $filename, $reason );
    }
  }

  output( sprintf(
    'Done, %d files %stransferred',
    $file_count,
    TEST_ONLY ? 'would be ' : ''
  ) );
}


/**
 * Processes all audio files
 */
function process_audio_files()
{
  $base_dir = sprintf( '%s/%s', DATA_DIR, TEMPORARY_DIR );

  // Process all audio recordings
  // There is a directory for each cohort, and a sub-directory for each phase named "f1", "f2", etc.
  // Each of these directories contain a directory based on the participant's CLSA ID, and in it multiple files.
  // Each file has the format: "<cohort>/<phase>/<uid>/<various>.wav"
  output( sprintf( 'Processing audio files in "%s"', $base_dir ) );

  // start by deleting all non-live instances
  foreach( glob( sprintf( '%s/*/audio/tracking/*', $base_dir ) ) as $dirname )
  {
    if( 0 === preg_match( '/-live$/', $dirname ) )
    {
      if( TEST_ONLY || KEEP_FILES )
      {
        if( VERBOSE ) output( sprintf(
          'Non-production audio directory "%s" would be removed.',
          $dirname
        ) );
      }
      else
      {
        if( VERBOSE ) output( sprintf(
          'Removing non-production audio directory "%s"',
          $dirname
        ) );
        exec( sprintf( 'rm -rf %s', format_filename( $dirname ) ) );
      }
    }
  }

  // now go through files that are labelled by UID (valid audio recordings)
  $file_count = 0;
  foreach( glob( sprintf( '%s/*/audio/*/*/*/*.wav', $base_dir ) ) as $filename )
  {
    $matches = [];
    if( false === preg_match( '#/([^/]+)/([^/]+)/([^/]+)/([^/]+).wav$#', $filename, $matches ) )
    {
      $reason = sprintf(
        'Ignoring invalid audio file: "%s"',
        $filename
      );
      move_from_temporary_to_invalid( $filename, $reason );
      continue;
    }

    $cohort = $matches[1];
    $phase_name = $matches[2];
    $uid = $matches[3];
    $name = $matches[4];

    $phase = NULL;
    $study = NULL;
    $destination_filename = NULL;
    if( 'comprehensive' == $cohort )
    {
      // comprehensive phase names are f1, f2, etc...
      $study = 'clsa';
      $phase = intval( substr( $phase_name, 1, 1 ) ) + 1;
      $variable = preg_replace( '/_(COF[1-9]|DCS)$/', '', $name );

      if( 'FAS_FREC' == $variable ) $destination_filename = 'f_word_fluency';
      else if( 'FAS_AREC' == $variable ) $destination_filename = 'a_word_fluency';
      else if( 'FAS_SREC' == $variable ) $destination_filename = 's_word_fluency';
      else if( 'STP_DOTREC' == $variable ) $destination_filename = 'stroop_dot';
      else if( 'STP_WORREC' == $variable ) $destination_filename = 'stroop_word';
      else if( 'STP_COLREC' == $variable ) $destination_filename = 'stroop_colour';
      else if( 'COG_ALPTME_REC2' == $variable ) $destination_filename = 'alphabet';
      else if( 'COG_ALTTME_REC' == $variable ) $destination_filename = 'mental_alternation';
      else if( 'COG_ANMLLLIST_REC' == $variable ) $destination_filename = 'animal_fluency';
      else if( 'COG_CNTTMEREC' == $variable ) $destination_filename = 'counting';
      else if( 'COG_WRDLST2_REC' == $variable ) $destination_filename = 'delayed_word_list';
      else if( 'COG_WRDLSTREC' == $variable ) $destination_filename = 'immediate_word_list';
    }
    else if( 'tracking' == $cohort )
    {
      // tracking phase names refer to the application, study phase and instance:
      // sabretooth_f1-live, sabretooth_f2-live, sabretooth_c1-live, etc
      if( 0 === preg_match( '/sabretooth_(bl|cb|[cf][1-9])-live/', $phase_name, $matches ) )
      {
        $reason = sprintf(
          'Ignoring invalid audio file: "%s"',
          $filename
        );
        move_from_temporary_to_invalid( $filename, $reason );
        continue;
      }

      $code = $matches[1];

      if( 'bl' == $code || 'f' == substr( $code, 0, 1 ) )
      {
        // bl is baseline (phase 1), otherwise f1, f2, etc...
        $phase = 'bl' == $code ? 1 : intval( substr( $code, 1, 1 ) ) + 1;
        $study = 'clsa';
      }
      else if( 'cb' == $code || 'c' == substr( $code, 0, 1 ) )
      {
        // cb is baseline (phase 1), otherwise c1, c2, etc...
        $phase = 'cb' == $code ? 1 : intval( substr( $code, 1, 1 ) ) + 1;
        $study = 'covid_brain';
      }
      else
      {
        $reason = sprintf(
          'Ignoring invalid audio file "%s"',
          $filename
        );
        move_from_temporary_to_invalid( $filename, $reason );
        continue;
      }

      if( !is_null( $phase ) && !is_null( $study ) )
      {
        if( preg_match( '#([^-]+)-(in|out)#', $name, $matches ) )
        {
          $variable = strtolower( $matches[1] );
          $operator = 'in' == $matches[2];
          if( preg_match( '#^[0-9][0-9]$#', $variable ) ) $destination_filename = $variable;
          else if( 'alphabet' == $variable ) $destination_filename = 'alphabet';
          else if( 'mat alternation' == $variable ) $destination_filename = 'mental_alternation';
          else if( 'animal list' == $variable ) $destination_filename = 'animal_fluency';
          else if( 'counting to 20' == $variable ) $destination_filename = 'counting';
          else if( 'rey i' == $variable ) $destination_filename = 'immediate_word_list';
          else if( 'rey ii' == $variable ) $destination_filename = 'delayed_word_list';
          else fatal_error( sprintf( 'Invalid filename "%s"', $filename ), 3 );

          // add the operator tag if the audio is of the operator (in)
          if( !is_null( $destination_filename ) && $operator ) $destination_filename .= '-operator';
        }
      }
    }

    if( !is_null( $study ) && !is_null( $phase ) && !is_null( $destination_filename ) )
    {
      $destination_directory = sprintf(
        '%s/raw/%s/%s/audio/%s',
        DATA_DIR,
        $study,
        $phase,
        $uid
      );

      // make sure the directory exists (recursively)
      if( !is_dir( $destination_directory ) ) mkdir( $destination_directory, 0755, true );

      // determine the destination filename based on
      $destination = sprintf( '%s/%s.wav', $destination_directory, $destination_filename );
      if( TEST_ONLY ? true : copy( $filename, $destination ) )
      {
        if( VERBOSE ) output( sprintf( '"%s" => "%s"', $filename, $destination ) );
        if( !TEST_ONLY && !KEEP_FILES ) unlink( $filename );
        $file_count++;
      }
      else
      {
        $reason = sprintf(
          'Failed to copy "%s" to "%s"',
          $filename,
          $destination
        );
        move_from_temporary_to_invalid( $filename, $reason );
      }
    }
    else
    {
      $reason = sprintf(
        'Unable to process file "%s"',
        $filename
      );
      move_from_temporary_to_invalid( $filename, $reason );
    }
  }

  // now remove all empty directories
  foreach( glob( sprintf( '%s/*/audio/*/*', $base_dir ) ) as $dirname )
  {
    if( is_dir( $dirname ) ) remove_dir( $dirname );
  }

  // any remaining files are to be moved to the invalid directory for data cleaning
  foreach( glob( sprintf( '%s/*/audio/*/*/*/*', $base_dir ) ) as $dirname )
  {
    $reason = sprintf(
      'Unable to sort directory, "%s"',
      $dirname
    );
    move_from_temporary_to_invalid( $dirname, $reason );
  }

  output( sprintf(
    'Done, %d files %stransferred',
    $file_count,
    TEST_ONLY ? 'would be ' : ''
  ) );
}


/**
 * Processes all ticwatch files
 * 
 * @param string $identifier_name The name of the identifier used in ticwatch filenames
 * @param string $study The name of the study that files come from
 * @param integer $phase The phase of the study that files come from
 */
function process_ticwatch_files( $identifier_name, $study, $phase )
{
  $base_dir = sprintf( '%s/%s', DATA_DIR, TEMPORARY_DIR );
  $study_uid_lookup = get_study_uid_lookup( $identifier_name, false, true ); // include consent data

  // Process all Ticwatch files
  // Each site has their own directory, and in each site directory there are sub-directories for
  // each modality (actigraph, ticwatch, etc).  Within the ticwatch directory there are directories
  // named after the participant's study_id, and another sub-directory with the serial number.
  // For example: "temporary/XXX/ticwatch/<study_id>/<serial>"
  output( sprintf( 'Processing ticwatch directories in "%s"', $base_dir ) );
  $dir_count = 0;
  $file_count = 0;
  foreach( glob( sprintf( '%s/[A-Z][A-Z][A-Z]/ticwatch/*/*', $base_dir ), GLOB_ONLYDIR ) as $serial_dirname )
  {
    $study_dirname = preg_replace( '#/[^/]+$#', '', $serial_dirname );
    $matches = [];
    if( false === preg_match( '#/([^/]+)/([^/]+)$#', $serial_dirname, $matches ) )
    {
      fatal_error( sprintf( 'Error while processing directory "%s"', $serial_dirname ), 4 );
    }

    $original_study_id = $matches[1];
    $study_id = strtoupper( trim( $original_study_id ) );
    if( !array_key_exists( $study_id, $study_uid_lookup ) )
    {
      $reason = sprintf(
        'Cannot transfer ticwatch directory due to missing UID lookup for study ID "%s"',
        $study_id
      );
      move_from_temporary_to_invalid( $study_dirname, $reason );
      continue;
    }
    $uid = $study_uid_lookup[$study_id]['uid'];
    $mobility_consent = $study_uid_lookup[$study_id]['mobility_consent'];

    // make sure the participant has consented to mobility trackers
    if( !$mobility_consent )
    {
      $reason = sprintf(
        'Ticwatch data without mobility consent, "%s".',
        $study_dirname
      );
      move_from_temporary_to_invalid( $study_dirname, $reason );
      continue;
    }

    $destination_directory = sprintf(
      '%s/raw/%s/%s/ticwatch/%s',
      DATA_DIR,
      $study,
      $phase,
      $uid
    );

    // make sure the directory exists (recursively)
    if( !TEST_ONLY && !is_dir( $destination_directory ) ) mkdir( $destination_directory, 0755, true );

    // make a list of all files to be copied and note the latest date
    $latest_date = NULL;
    $file_pair_list = [];
    foreach( glob( sprintf( '%s/*', $serial_dirname ) ) as $filename )
    {
      $destination_filename = substr( $filename, strrpos( $filename, '/' )+1 );

      // remove any identifiers from the filename
      $destination_filename = preg_replace(
        sprintf( '/^%s_/', $study_id ),
        '',
        $destination_filename
      );

      // see if there is a date in the filename that comes after the latest date
      if( preg_match( '#_(20[0-9]{6})\.#', $destination_filename, $matches ) )
      {
        $date = intval( $matches[1] );
        if( is_null( $latest_date ) || $date > $latest_date ) $latest_date = $date;
      }

      // remove the unneeded filename details
      $destination = str_replace( 'TicWatch Pro 3 Ultra GPS_', '', $destination_filename );
      $destination = str_replace( sprintf( '%s_', $original_study_id ), '', $destination );
      $destination = sprintf( '%s/%s', $destination_directory, $destination );

      $file_pair_list[] = [
        'source' => $filename,
        'destination' => $destination
      ];
    }

    // only copy files if they are not older than any files in the destination directory
    $latest_existing_date = NULL;
    foreach( glob( sprintf( '%s/*', $destination_directory ) ) as $filename )
    {
      $existing_filename = substr( $filename, strrpos( $filename, '/' )+1 );

      // see if there is a date in the filename that comes after the latest date
      if( preg_match( '#_(20[0-9]{6})\.#', $existing_filename, $matches ) )
      {
        $date = intval( $matches[1] );
        if( is_null( $latest_existing_date ) || $date > $latest_existing_date )
        {
          $latest_existing_date = $date;
        }
      }
    }

    // delete the local files if they are not newer than existing files
    if( !is_null( $latest_existing_date ) && $latest_date <= $latest_existing_date )
    {
      if( VERBOSE ) output( sprintf(
        'Ignoring files in %s as there already exists more recent files',
        $study_dirname
      ) );
      if( !TEST_ONLY && !KEEP_FILES ) exec( sprintf( 'rm -rf %s', format_filename( $study_dirname ) ) );
    }
    else
    {
      // otherwise remove any existing files
      if( !TEST_ONLY ) exec( sprintf( 'rm -rf %s', format_filename( $destination_directory.'/*' ) ) );

      // then copy the local files to their destinations (deleting them as we do)
      $success = true;
      foreach( $file_pair_list as $file_pair )
      {
        $copy = TEST_ONLY ? true : copy( $file_pair['source'], $file_pair['destination'] );
        if( $copy )
        {
          if( VERBOSE ) output( sprintf( '"%s" => "%s"', $file_pair['source'], $file_pair['destination'] ) );
          if( !TEST_ONLY && !KEEP_FILES ) unlink( $file_pair['source'] );
          $file_count++;
        }
        else
        {
          output( sprintf(
            'Failed to copy "%s" to "%s"',
            $file_pair['source'],
            $file_pair['destination']
          ) );
          $success = false;
        }
      }

      if( !TEST_ONLY && !KEEP_FILES )
      {
        if( $success )
        {
          // we can now delete the directory as all files were successfully moved
          remove_dir( $study_dirname );
        }
        else
        {
          // move the remaining files to the invalid directory
          $reason = sprintf(
            'Unable to sort directory, "%s"',
            $study_dirname
          );
          move_from_temporary_to_invalid( $study_dirname, $reason );
        }
      }
    }
    $dir_count++;
  }
  output( sprintf(
    'Done, %d files %stransferred from %d directories',
    $file_count,
    TEST_ONLY ? 'would be ' : '',
    $dir_count
  ) );
}


/**
 * Anonymizes a DICOM file by removing identifying tags
 * @param string $data_type The type of data being anonymize (cimt or dxa)
 * @param string $filename The name of the file to anonymize
 */
function anonymize_dicom( $data_type, $filename, $debug = false )
{
  $tag_list = [
    '0008,1010' => '',      // Station Name
    '0008,0080' => 'CLSA',  // Instituion Name
    '0008,1040' => 'NCC',   // Instituion Department Name
    '0008,1070' => '',      // Operators Name
    '0010,0010' => '',      // Patient Name
    '0010,0020' => '',      // Patient ID
    '0010,1000' => '',      // Other Patient IDs
    '0018,1000' => '',      // Device Serial Number
  ];

  if( 'cimt' == $data_type )
  {
    $tag_list['0008,1010'] = 'VIVID_I'; // Station Name
  }
  else if( 'dxa' == $data_type )
  {
    // Unknown Tags & Data
    $tag_list['0019,1000'] = NULL;
    $tag_list['0023,1000'] = NULL;
    $tag_list['0023,1001'] = NULL;
    $tag_list['0023,1002'] = NULL;
    $tag_list['0023,1003'] = NULL;
    $tag_list['0023,1004'] = NULL;
    $tag_list['0023,1005'] = NULL;
  }
  else return;

  $modify_list = [];
  foreach( $tag_list as $tag => $value )
  {
    $modify_list[] = sprintf( '-m "(%s)%s"', $tag, is_null( $value ) ? '' : sprintf( '=%s', $value ) );
  }

  $command = sprintf( 'dcmodify -nb -nrc -imt %s %s', implode( ' ', $modify_list ), format_filename( $filename ) );

  $result_code = 0;
  $output = NULL;
  $debug ? printf( "%s\n", $command ) : exec( $command, $output, $result_code );

  if( 0 < $result_code ) printf( implode( "\n", $output ) );
}


/**
 * Anonymizes an ECG XML file by removing identifying data
 * @param string $filename The name of the file to anonymize
 */
function anonymize_ecg( $filename, $debug = false )
{
  // remove the body of the Facility element
  $command = sprintf(
    'sed -i "s#<Facility>[^<]\+</Facility>#<Facility></Facility>#" %s',
    format_filename( $filename )
  );
  $result_code = 0;
  $output = NULL;
  $debug ? printf( "%s\n", $command ) : exec( $command, $output, $result_code );
  if( 0 < $result_code ) printf( implode( "\n", $output ) );

  // remove the body of the Name element
  $command = sprintf(
    'sed -i "s#<Name>[^<]\+</Name>#<Name></Name>#" %s',
    format_filename( $filename )
  );
  $result_code = 0;
  $output = NULL;
  $debug ? printf( "%s\n", $command ) : exec( $command, $output, $result_code );
  if( 0 < $result_code ) printf( implode( "\n", $output ) );

  // remove the body of the PID element
  $command = sprintf(
    'sed -i "s#<PID>[^<]\+</PID>#<PID></PID>#" %s',
    format_filename( $filename )
  );
  $result_code = 0;
  $output = NULL;
  $debug ? printf( "%s\n", $command ) : exec( $command, $output, $result_code );
  if( 0 < $result_code ) printf( implode( "\n", $output ) );
}


/**
 * Prepares a dicom file by anonymizing it and setting a new identifier
 * 
 * @param string The type of file to prepare (ecg, cimt or dxa)
 * @param string $filename The name of the DICOM file to modify
 * @param string $identifier The identifier to set
 */
function prepare_file( $type, $filename, $identifier )
{
  $result_code = 0;
  $output = NULL;
  if( in_array( $type, ['cimt', 'dxa'] ) )
  {
    anonymize_dicom( $type, $filename );
    exec(
      sprintf( 'dcmodify -nb -nrc -imt -m "(0010,0020)=%s" %s', $identifier, format_filename( $filename ) ),
      $output,
      $result_code
    );
  }
  else if( 'xml' == $type )
  {
    anonymize_ecg( $filename );
    exec(
      sprintf( 'sed -i "s#<PID>[^<]*</PID>#<PID>%s</PID>#" %s', $identifier, format_filename( $filename ) ),
      $output,
      $result_code
    );
  }
  else throw new Exception( sprintf( 'Tried to prepare invalid file type "%s"', $type ) );

  if( 0 < $result_code ) printf( implode( "\n", $output ) );
  return $result_code;
}

/**
 * Decompresses a gzipped file and returns the full path to the decompressed file
 */
function decompress_file( $gzip_filename )
{
  // /data/raw/clsa/4/carotid_intima/A004547/report_1.dcm.gz: gzip compressed data, from FAT filesystem (MS-DOS, OS/2, NT), original size modulo 2^32 8544
  $decompressed_filename = NULL;

  // determine if the input is a gzipped file
  $output = NULL;
  $result_code = NULL;
  exec( sprintf( 'file %s', $gzip_filename ), $output, $result_code );
  if( 0 == $result_code && 0 < count( $output ) && preg_match( '#gzip compressed data#', $output[0] ) )
  {
    // create a temporary file to copy the original gzipped to
    $working_gzfilename = sprintf(
      '/tmp/%s_%s',
      bin2hex( openssl_random_pseudo_bytes( 8 ) ),
      basename( $gzip_filename )
    );

    // add the .gz extension if there isn't one already
    if( !preg_match( '#\.gz$#', $working_gzfilename ) ) $working_gzfilename .= '.gz';

    copy( $gzip_filename, $working_gzfilename );
    exec( sprintf( 'gunzip -f %s', $working_gzfilename ), $output, $result_code );

    // determine what the decompressed file will be
    if( 0 == $result_code ) $decompressed_filename = preg_replace( '#\.gz$#', '', $working_gzfilename );
  }

  return $decompressed_filename;
}
