<?php
/**
 * A simple SMS sender for Twilio.
 * @copyright Copyright (c) mustafa.0x
 * @license @see LICENSE
 */
$twilio_path = 'twilio/Services/Twilio.php';
$acount_sid = '';
$auth_token = '';
$twilio_number = '123-456-7890';
$log_file = 'sms-send.log';
$csv_dir = 'csv';
$csv_column = 0; // Which CSV column is the number field
if (!is_dir($csv_dir))
	mkdir($csv_dir);
if (!file_exists($log_file))
	touch($log_file);
// Security measures
if (!file_exists($csv_dir . '/index.html'))
	touch($csv_dir . '/index.html');
if (!file_exists($csv_dir . '/.htaccess'))
	file_put_contents($csv_dir . '/.htaccess', "Order Deny,Allow\nDeny From All");
chmod($log_file, 0600);
if (isset($_GET['action'])) {
	switch ($_GET['action']) {
		case 'upload_csv':
			upload_csv();
			break;
		case 'sms_send':
			sms_send();
			break;
		case 'duplicate_check':
			duplicate_check($csv_dir . '/' . $_POST['list']);
			break;
		case 'protect_me':
			protect_me($_POST['username'], $_POST['password']);
			break;
	}
}
function build_csv_files_html() {
	global $csv_dir;
	$out = '';
	foreach (glob($csv_dir . '/*.csv') as $list) {
		$out .= sprintf('<option value="%s">%1$s</option>', basename($list));
	}
	$GLOBALS['csv_files_html'] = $out;
}
build_csv_files_html();
function init_twilio() {
	global $twilio_path, $acount_sid, $auth_token;
	require $twilio_path;
	return new Services_Twilio($acount_sid, $auth_token);
}
function upload_csv() {
	global $csv_dir;
	if (!$_FILES)
		exit('No uploaded file found.');
	$file = $_FILES['file'];
	if ($file['error'])
		return $file['error'];
	if (!is_file($file['tmp_name']) || !is_uploaded_file($file['tmp_name']))
		return $file['name'] . ' could not be uploaded.';
	$ext = strtolower(substr($file['name'], strrpos($file['name'], '.') + 1));
	if ($ext != 'csv') {
		unlink($file['tmp_name']);
		exit('Uploaded file must end in \'.csv\'');
	}
	move_uploaded_file($file['tmp_name'], $csv_dir . '/' . basename($file['name']));
	header('Location: ?');
	exit();
}
function log_sms($sms) {
	global $log_file;
	$log_line = date('Y-m-d H:i:s') . ' | ' . $sms->to . ' | ' . $sms->status . "\n";
	if ($log_file)
		file_put_contents($log_file, $log_line, FILE_APPEND);
	return $log_line;
}
function load_numbers($file) {
	global $csv_column;
	$numbers = array();
	$row = 0;
	if (($handle = fopen($file, 'r')) !== false) {
		while (($data = fgetcsv($handle, 128, ',')) !== false) {
			if ($row++ < 1 || $data[$csv_column] == '')
				continue; // It is assumed that the first row is a header.
			$numbers[] = $data[$csv_column];
		}
		fclose($handle);
	}
	return $numbers;
}
function protect_me($username, $password){
	$htaccess_contents = sprintf('AuthUserFile %s/.htpasswd
AuthName "SMS Sender"
AuthType Basic
<Files "%s">
  require valid-user
</Files>', __DIR__, basename(__FILE__));
	file_put_contents('.htpasswd', $username . ':' . crypt($password));
	file_put_contents('.htaccess', $htaccess_contents);
	header('Location: ?');
	exit();
}
function duplicate_check($file){
	$numbers = load_numbers($file);
	$l = count($numbers);
	$out = '';
	foreach(array_count_values($numbers) as $k => $v){
		if ($v > 1)
			$out .= $k . "<br>\n";
	}
	if ($out)
		echo "Duplicates:<br>\n" . $out;
	else
		echo 'No duplicates found.<br>';
	echo "<br>\n";
}
function sms_send() {
	global $csv_dir;
	if (isset($_POST['number']) && $_POST['number'])
		single_send($_POST['number'], $_POST['message']);
	else
		mass_send($csv_dir . '/' . $_POST['list'], $_POST['message']);
}
function single_send($number, $message){
	global $twilio_number, $client;
	$client = init_twilio();
	$sms = $client->account->sms_messages->create(
		$twilio_number,
		$number,
		$message
	);
	echo log_sms($sms) . "<br><br>\n";
}
function mass_send($number_list, $message){
	global $twilio_number, $client;
	$client = init_twilio();
	$numbers = load_numbers($number_list);
	$l = count($numbers);
	set_time_limit(0);
	header('Content-Type: text/html; charset=utf-8');
	for ($i = 0; $i < $l; $i++) {
		$sms = $client->account->sms_messages->create(
			$twilio_number,
			$numbers[$i],
			$message
		);
		echo log_sms($sms) . '<br>';
		flush();
		//sleep(1); // Twilio limits regular numbers to an sms/s.
	}
	echo "<br>\n";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>SMS Send</title>
  <meta charset="utf-8">
  <style>
	fieldset {
		width: 400px;
	}
	input[name=number] {
		width: 200px;
	}
  </style>
  <script type="text/javascript">
	onload = function(){
		function $(id) {
			return document.getElementById(id);
		}
		var number = $('number'), list = $('list');
		number.onkeyup = number.onchange = function() {
			list.disabled = !!this.value;
		}
		list.onchange = function() {
			number.disabled = this.selectedIndex > 0;
		}
	};
  </script>
</head>
<body>

<form action="?action=sms_send" method="post">
<fieldset>
  <legend style="text-align: left;">SMS Send</legend>
  <label>Single send:
    <input type="text" name="number" id="number" placeholder="international format, e.g. +12223334444">
  </label><br>

  <label>Mass send:
    <select name="list" id="list">
      <option value="-">---</option>
      <?php echo $csv_files_html; ?></select>
  </label><br>
  <label>Body: <br><textarea cols="40" rows="10" name="message"></textarea></label><br>
  <input type="submit" value="Send" title="Send">
</fieldset>
</form><br>

<form action="?action=upload_csv" method="post" enctype="multipart/form-data">
<fieldset>
  <legend style="text-align: left;">Upload Number List</legend>
  <label><input type="file" name="file"></label><br>
  <input type="submit" value="Upload CSV file" title="Upload">
</fieldset>
</form><br>

<form action="?action=duplicate_check" method="post">
<fieldset>
  <legend style="text-align: left;">Duplicate Check</legend>
  <label>List: <select name="list"><?php echo $csv_files_html; ?></select></label><br>
  <input type="submit" value="Duplicate Check" title="Duplicate Check">
</fieldset>
</form><br>

<form action="?action=protect_me" method="post">
<fieldset>
  <legend style="text-align: left;">Protect Me</legend>
  <label>Username: <input type="text" name="username"></label><br>
  <label>Password: <input type="password" name="password"></label><br>
  <em>Note: overwrites .htaccess</em><br>
  <input type="submit" value="Protect Me" title="Protect Me">
</fieldset>
</form>

</body>
</html>
