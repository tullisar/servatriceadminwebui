<?php

    namespace ServatriceAWUI;

    /**
     * An adapter for the wraped configuration class
     */
    class Config
    {
        private const USER_CHANGED_FILE = "user_changed.txt";
        private const CFG_DEFAULT_FILE = "config.ini";
        private PHLAK\Config\Config $config;
        private $timezone;

        public function __construct(string $file = self::CFG_FILE)
        {

            // Ensure that the file exists
            if (!file_exists($file)) {
                throw new Exception("Fatal Error: Config file '$file' does not exist");
            }

            $config = new Config($file);

            $timezone = $this->get('timezone');
            date_default_timezone_set($timezone); // Matches up with MySQL
        }

        /**
         * Gets a value from the configuration with the given key.
         *
         * @param string $key the key to look up in the configuration
         * @param mixed $default the default value to return
         * @return mixed the value of the key or null.
         */
        public function get(string $key, mixed $default = null) : mixed
        {
            return $config->get($key, $default);
        }

        /**
         * Sets the value of a key to a given value
         *
         * @param string $key the key to set
         * @param mixed $value the value to set
         * @return bool true if the value was set
         */
        public function set(string $key, mixed $value) : bool
        {
            return $config->set($key, $value);
        }

        /**
         * Determines if the configuration has a value for the given key.
         *
         * @param string $key the key to check
         * @return bool true if the config has a value for the key, false if not.
         */
        public function has(string $key) : bool
        {
            return $config->has($key);
        }

        /**
         * Appends the given value to a key with an array value in the configuration.
         *
         * @param string $key the key for which to set the array value
         * @param mixed $value the value to append
         * @return bool true if the value was appended, false if not.
         */
        public function append(string $key, mixed $value) : bool
        {
            return $config->append($key, $value);
        }
    }

    

    /**
    *
    */
    function already_changed_name($id_to_find)
    {
        global $configfile;
        if (!file_exists($configfile) || !file_get_contents($configfile)) {
            file_put_contents($configfile, "\n");
        }

        $file_handle = file_get_contents($configfile) or die("Error: Username update failed; Contact support [Error #2]");
        $all_ids = explode("\n", $file_handle);

        if (in_array($id_to_find, $all_ids)) {
            return true;
        }

        return false;
    }

    function write_changed_name($configfile, $id_to_add)
    {
        $file_handle = fopen($configfile, "a") or die("Error: Username update failed; Contact support [Error #3]");

        if (fwrite($file_handle, "$id_to_add\n")) {
            fclose($file_handle);
            return true;
        }

        fclose($file_handle);
        return false;
    }

    function change_username($old_user, $new_user, $password)
    {
        global $configfile, $saved_user_id_change_file;
        $db_initial_connection = connect_to_database(get_config_value($configfile, "dbserver"), get_config_value($configfile, "dbusername"), get_config_value($configfile, "dbpassword"), get_config_value($configfile, "dbname"));

        $user_table = get_config_value($configfile, "dbusertable");
        $ban_table = get_config_value($configfile, "dbbantable");

        $old = mysqli_real_escape_string($old_user);
        $new = mysqli_real_escape_string($new_user);

        $info_q = mysqli_fetch_array(mysqli_query("SELECT `password_sha512`,`admin`,`id` FROM `$user_table` WHERE name = '$old' LIMIT 0,1"));
        $pass_encrypt = crypt_password($password, trim(substr($info_q[0], 0, 16)));

        $is_banned = mysqli_num_rows(mysqli_query("SELECT `reason` FROM `$ban_table` WHERE `user_name` = '$old' AND DATE_ADD(`time_from`, INTERVAL `minutes` MINUTE) > NOW() LIMIT 0,1"));
        $is_valid_user = mysqli_num_rows(mysqli_query("SELECT `id` FROM `$user_table` WHERE name = '$old' AND `password_sha512` = '$pass_encrypt' LIMIT 0,1"));

        if ($is_valid_user) { // Valid Login
            if ($is_banned) { // Banned Account
                return "Error: You cannot change the username of a banned account";
            } elseif (!preg_match("/^[a-zA-Z0-9_\\.-]+$/", $new)) { // New Name Has Bad Characters
                return "Error: You may only use A-Z, a-z, 0-9, _, -, and . in your new username";
            } elseif (preg_match('/^Player_\d+$/', $new)) { // New Name Starts "Player_"
                return "Error: You cannot start your new username with 'Player_'";
            }
            /*else if (preg_match("/^[a-zA-Z0-9_\\.-]+$/", $old)) // Old Name Is Fine Already
            {
                return "Error: You may only change your username if your name was invalid";
            }*/
            elseif ($info_q[1] > 0) { // Moderator
                return "Error: Moderators may not change their username without approval";
            } elseif (strlen($new) > 30) { // New Name Over 30 Characters
                return "Error: You may only use up to 30 characters in your new username";
            } elseif (already_changed_name($saved_user_id_change_file, $info_q[2])) { // Already Changed Username Once
                return "Error: You may only change your username once";
            }

            $username_is_taken = mysqli_num_rows(mysqli_query("SELECT `id` FROM `$user_table` WHERE name = '$new' AND name != '$old' LIMIT 0,1"));

            if (!$username_is_taken) { // New Username Is Not Taken
                // 1) Write ID in log; 2) Update ban table; 3) Update user table
                if (write_changed_name($saved_user_id_change_file, $info_q[2])
                    && mysqli_query("UPDATE `$ban_table` SET `user_name` = '$new' WHERE `user_name` = '$old'")
                    && update_user_table($old, 'name', $new)) {
                    return "Username updated successfully<br/>\"$old\" &rarr; \"$new\"";
                }

                return "Error: Username update failed; Contact support [Error #1]";
            } else {
                return "Error: Username \"$new\" taken already";
            }
        } else {
            return "Error: Invalid Username/Password combination";
        }
    }

    function calculate_string($mathString)
    {
        $mathString = trim($mathString);
        $mathString = ereg_replace('[^0-9\+-\*\/\(\) ]', '', $mathString);

        $compute = create_function("", "return (" . $mathString . ");");
        return 0 + $compute();
    }

    function build_log_table()
    {
        // I don't feel like rewriting this entire thing again... so I'll just make it work here
        global $_REQUEST, $configfile;

        $use_archive_server = (get_config_value($configfile, "use_archive_server") === 'true');

        $db_initial_connection = connect_to_database(get_config_value($configfile, "dbserver"), get_config_value($configfile, "dbusername"), get_config_value($configfile, "dbpassword"), get_config_value($configfile, "dbname"));

        if ($use_archive_server) {
            $db_archive_connection = connect_to_database(get_config_value($configfile, "archivedbserver"), get_config_value($configfile, "archivedbusername"), get_config_value($configfile, "archivedbpassword"), get_config_value($configfile, "archivedbname"), true);
        }

        if (strlen($_REQUEST['username']) > 0) { // Check if a username is given
            $username = strtolower(mysqli_real_escape_string($_REQUEST['username']));
        }

        if (strlen($_REQUEST['ip_address']) > 0) { // Check if IP is given
            $ip_to_find = mysqli_real_escape_string($_REQUEST['ip_address']);
        }

        if (strlen($_REQUEST['client_id']) > 0) { // Check if Client ID is given
            $client_id = mysqli_real_escape_string($_REQUEST['client_id']);
        }

        if (intval($_REQUEST['game_id'] > 0)) { // Check if Game ID is given
            $game_id = intval(mysqli_real_escape_string($_REQUEST['game_id']));
        }

        if (strlen($_REQUEST['game_name']) > 0) { // Check if Game Name is given
            $game_name = mysqli_real_escape_string(strtolower($_REQUEST['game_name']));
        }

        if ($_REQUEST['from_date']) { // Check if date range is given
            $total_time = mysqli_real_escape_string($_REQUEST['from_date']);
        }

        if (strlen($_REQUEST['message']) > 0) { // Check if Message is given
            $message = mysqli_real_escape_string($_REQUEST['message']);
        }

        if (count($_REQUEST['type_of_chat']) > 0) { // Check if any specific locations are given
            $get_logs_from = array();
            for ($i = 0; $i < 3; $i++) {
                if (isset($_REQUEST['type_of_chat'][$i])) {
                    $get_logs_from[] = mysqli_real_escape_string($_REQUEST['type_of_chat'][$i]);
                }
            }
        }

        /* BUILD QUERY BASE */
        $max_results_q = (intval($_REQUEST['max_results']) > 0) ? "LIMIT 0," . intval($_REQUEST['max_results']) : "LIMIT 0,500"; // Default is 500
        $user_q = (isset($username)) ? "AND (`sender_name` = '$username' OR `target_name` = '$username')" : "";
        $ip_q = isset($ip_to_find) ? "AND `sender_ip` = '$ip_to_find'" : "";
        $client_id_q = isset($client_id) ? "AND `clientid` = '$client_id'" : "";
        $game_id_q = isset($game_id) ? "AND (`target_id` = '$game_id' AND `target_type` = 'game')" : "";
        $game_name_q = isset($game_name) ? "AND (`target_name` = '$game_name' AND `target_type` = 'game')" : "";
        $message_q = isset($message) ? "AND `log_message` LIKE '%$message%'" : "";

        switch ($total_time) {
            case "week":
                $date_q = "AND `log_time` >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
            break;

            case "day":
                $date_q = "AND `log_time` >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
            break;

            case "hour":
                $date_q = "AND `log_time` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
            break;

            default:
                $date_q = "";
            break;
        }

        // Print out the tables, but in the format: Room, Game, Chat (3 different tables) based on what's requested
        if (count($get_logs_from) == 0 && !isset($game_id) && !isset($game_name)) { // Default: Show all 3 Tables
            $get_logs_from = array("room", "game", "chat");
        } elseif (isset($game_id) || isset($game_name)) { // If getting a game room log, must change it to just show game room
            $get_logs_from = array("game");
        }

        for ($i = 0; $i < count($get_logs_from); $i++) {
            $target_type_q = (strlen($game_id_q) == 0) ? "AND `target_type` = '{$get_logs_from[$i]}'" : ""; // Builds target query and avoids issues

            $query = mysqli_query("SELECT * FROM `cockatrice_log` WHERE `sender_ip` IS NOT NULL $user_q $date_q $ip_q $client_id_q $game_id_q $target_type_q $game_name_q $message_q ORDER BY -log_time $max_results_q", $db_initial_connection) or die(mysqli_error());
            if ($use_archive_server) {
                $query_archive = mysqli_query("SELECT * FROM `cockatrice_log` WHERE `sender_ip` IS NOT NULL $user_q $date_q $ip_q $client_id_q $game_id_q $target_type_q $game_name_q $message_q ORDER BY -log_time $max_results_q", $db_archive_connection) or die(mysqli_error());
            }

            switch ($get_logs_from[$i]) {
                case "room":
                    echo "<table border=1><tr><th colspan=4>Main Chat Room Logs</th></tr>\n";
                    echo "<tr><th style='width:150px'>Sender</th><th style='width:120px'>IP Address</th><th style='width:300px'>Message</th><th style='width:150px'>Time Stamp</th></tr>\n";
                    while ($row = mysqli_fetch_array($query)) {
                        $senderusername = $row['sender_name'];
                        $name_with_link="";
                        if (strcmp($username, $senderusername) == 0) {
                            $name_with_link = "<a href='?username=$senderusername'><font color=\"red\">$senderusername</font></a>";
                        } else {
                            $name_with_link = "<a href='?username=$senderusername'>$senderusername</a>";
                        }


                        $ip = $row['sender_ip'];
                        $ip_with_link = "<a href='?ip_address=$ip'>$ip</a>";

                        $log_message = htmlspecialchars($row['log_message'], ENT_COMPAT | ENT_XHTML, 'ISO-8859-1');

                        echo "<tr><td>$name_with_link</td><td>$ip_with_link</td><td>$log_message</td><td>{$row['log_time']}</td></tr>\n";
                    }

                    if ($use_archive_server) {
                        while ($row = mysqli_fetch_array($query_archive)) {
                            $senderusername = $row['sender_name'];
                            $name_with_link="";
                            if (strcmp($username, $senderusername) == 0) {
                                $name_with_link = "<a href='?username=$senderusername'><font color=\"red\">$senderusername</font></a>";
                            } else {
                                $name_with_link = "<a href='?username=$senderusername'>$senderusername</a>";
                            }

                            $ip = $row['sender_ip'];
                            $ip_with_link = "<a href='?ip_address=$ip'>$ip</a>";

                            $log_message = htmlspecialchars($row['log_message'], ENT_COMPAT | ENT_XHTML, 'ISO-8859-1');

                            echo "<tr><td>$name_with_link</td><td>$ip_with_link</td><td>$log_message</td><td>{$row['log_time']}</td></tr>\n";
                        }
                    }

                    echo "</table><br/>\n";
                break;

                case "game":
                    echo "<table border=1><tr><th colspan=6>Game Chat Logs</th></tr>\n";
                    echo "<tr><th style='width:150px'>Sender</th><th style='width:120px'>IP Address</th><th style='width:300px'>Message</th><th style='width:150px'>Game ID</th><th style='width:200px'>Game Name</th><th style='width:150px'>Time Stamp</th></tr>\n";
                    while ($row = mysqli_fetch_array($query)) {
                        $senderusername = $row['sender_name'];
                        $name_with_link="";
                        if (strcmp($username, $senderusername) == 0) {
                            $name_with_link = "<a href='?username=$senderusername'><font color=\"red\">$senderusername</font></a>";
                        } else {
                            $name_with_link = "<a href='?username=$senderusername'>$senderusername</a>";
                        }

                        $ip = $row['sender_ip'];
                        $ip_with_link = "<a href='?ip_address=$ip'>$ip</a>";

                        $room_id = $row['target_id'];
                        $room_id_with_link = "<a href='?game_id=$room_id'>$room_id</a>";

                        $game_name = $row['target_name'];
                        $game_name_with_link = "<a href='?game_name=$game_name'>$game_name</a>";

                        $log_message = htmlspecialchars($row['log_message'], ENT_COMPAT | ENT_XHTML, 'ISO-8859-1');

                        echo "<tr><td>$name_with_link</td><td>$ip_with_link</td><td>$log_message</td><td>$room_id_with_link</td><td>$game_name_with_link</td><td>{$row['log_time']}</td></tr>\n";
                    }
                    if ($use_archive_server) {
                        while ($row = mysqli_fetch_array($query_archive)) {
                            $senderusername = $row['sender_name'];
                            $name_with_link="";
                            if (strcmp($username, $senderusername) == 0) {
                                $name_with_link = "<a href='?username=$senderusername'><font color=\"red\">$senderusername</font></a>";
                            } else {
                                $name_with_link = "<a href='?username=$senderusername'>$senderusername</a>";
                            }

                            $ip = $row['sender_ip'];
                            $ip_with_link = "<a href='?ip_address=$ip'>$ip</a>";

                            $room_id = $row['target_id'];
                            $room_id_with_link = "<a href='?game_id=$room_id'>$room_id</a>";

                            $game_name = $row['target_name'];
                            $game_name_with_link = "<a href='?game_name=$game_name'>$game_name</a>";

                            $log_message = htmlspecialchars($row['log_message'], ENT_COMPAT | ENT_XHTML, 'ISO-8859-1');

                            echo "<tr><td>$name_with_link</td><td>$ip_with_link</td><td>$log_message</td><td>$room_id_with_link</td><td>$game_name_with_link</td><td>{$row['log_time']}</td></tr>\n";
                        }
                    }
                    echo "</table><br/>\n";
                break;

                case "chat":
                    echo "<table border=1><tr><th colspan=5>Private Chat Logs</th></tr>\n";
                    echo "<tr><th style='width:150px'>Sender</th><th style='width:120px'>IP Address</th><th style='width:300px'>Message</th><th style='width:200px'>Receiver</th><th style='width:150px'>Time Stamp</th></tr>\n";
                    while ($row = mysqli_fetch_array($query)) {
                        $senderusername = $row['sender_name'];
                        $name_with_link="";
                        if (strcmp($username, $senderusername) == 0) {
                            $name_with_link = "<a href='?username=$senderusername'><font color=\"red\">$senderusername</font></a>";
                        } else {
                            $name_with_link = "<a href='?username=$senderusername'>$senderusername</a>";
                        }

                        $ip = $row['sender_ip'];
                        $ip_with_link = "<a href='?ip_address=$ip'>$ip</a>";

                        $receiver = $row['target_name'];
                        $receiver_with_link = "<a href='?username=$receiver'>$receiver</a>";

                        $log_message = htmlspecialchars($row['log_message'], ENT_COMPAT | ENT_XHTML, 'ISO-8859-1');

                        echo "<tr><td>$name_with_link</td><td>$ip_with_link</td><td>$log_message</td><td>$receiver_with_link</td><td>{$row['log_time']}</td></tr>\n";
                    }

                    if ($use_archive_server) {
                        while ($row = mysqli_fetch_array($query_archive)) {
                            $senderusername = $row['sender_name'];
                            $name_with_link="";
                            if (strcmp($username, $senderusername) == 0) {
                                $name_with_link = "<a href='?username=$senderusername'><font color=\"red\">$senderusername</font></a>";
                            } else {
                                $name_with_link = "<a href='?username=$senderusername'>$senderusername</a>";
                            }

                            $ip = $row['sender_ip'];
                            $ip_with_link = "<a href='?ip_address=$ip'>$ip</a>";

                            $receiver = $row['target_name'];
                            $receiver_with_link = "<a href='?username=$receiver'>$receiver</a>";

                            $log_message = htmlspecialchars($row['log_message'], ENT_COMPAT | ENT_XHTML, 'ISO-8859-1');

                            echo "<tr><td>$name_with_link</td><td>$ip_with_link</td><td>$log_message</td><td>$receiver_with_link</td><td>{$row['log_time']}</td></tr>\n";
                        }
                    }
                    echo "</table><br/>\n";
                break;
            }
        }
        mysqli_close($dbconnection);
    }
    function insert_avatar($image, $username)
    {
        $results = "unknown";
        $configfile = ".config";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbusertable");

        //read in image file
        $fp = fopen($image, 'r');
        $data = fread($fp, filesize($image));
        $data = addslashes($data);
        fclose($fp);

        //establish db connection
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        //insert image into database
        $query = mysqli_query("UPDATE " . $dbtable . " SET avatar_bmp='" . $data . "' WHERE name='" . mysqli_real_escape_string($username) . "'");
        $results = $query;
        mysqli_close($dbconnection);
        return $results;
    }

    function delete_avatar($username)
    {
        $results = "unknown";
        $configfile = ".config";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbusertable");

        //establish db connection
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }

        //insert image into database
        $query = mysqli_query("UPDATE " . $dbtable . " SET avatar_bmp='' WHERE name='" . $username . "'");
        $results = $query;
        mysqli_close($dbconnection);
        return $results;
    }

    function connect_to_database($dbserver, $dbuser, $dbpass, $dbname, $extra = false)
    {
        if ($dbserver == '' || $dbuser == '' || $dbname == '') {
            return 'failed, database server, name, and user can not be blank';
        }

        if ($extra) {
            $connection = mysqli_connect(trim($dbserver), trim($dbuser), trim($dbpass), true) or die(mysqli_error());
        } else {
            $connection = mysqli_connect(trim($dbserver), trim($dbuser), trim($dbpass), trim($dbname)) or die(mysqli_error());
        }

        return $connection;
    }

    function crypt_password($password, $salt = '')
    {
        if ($salt == '') {
            $saltChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
            for ($i = 0; $i < 16; ++$i) {
                $salt .= $saltChars[rand(0, strlen($saltChars) - 1)];
            }
        }
        $key = $salt . $password;
        for ($i = 0; $i < 1000; ++$i) {
            $key = hash('sha512', $key, true);
        }
        return $salt . base64_encode($key);
    }

    function get_config_value($configfile, $configvalue)
    {
        $results = "unknown";
        if (empty($configfile)) {
            $results = "failed, config file name can not be blank";
            return $results;
            exit;
        }
        if (empty($configvalue)) {
            $results = "failed, config file value can not be blank";
            return $results;
            exit;
        }
        if (!file_exists($configfile)) {
            $results = "failed, config file does not exist";
            return $results;
            exit;
        }
        $file_handle = fopen($configfile, "r");
        while (!feof($file_handle)) {
            $line = fgets($file_handle);
            if (strpos($line, $configvalue) !== false) {
                $lineparts = explode("=", $line);
                if ($lineparts[0] == $configvalue) {
                    if (sizeof($lineparts) < 3 && sizeof($lineparts) > 1) {
                        $results = $lineparts[1];
                    }
                    break;
                }
            }
        }
        fclose($file_handle);
        return trim($results);
    }

    function close_complaint($moderator, $id, $verdict)
    {
        global $configfile;
        $closedate = date("Y-m-d H:i:s");
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbcoctable");
        if (empty($moderator)) {
            $results = "failed, moderator name can not be blank";
            return $results;
            exit;
        }
        if (empty($id)) {
            $results = "failed, id can not be blank";
            return $results;
            exit;
        }
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("UPDATE " . trim($dbtable) . " SET closingmod='" . trim($moderator) . "', dateresolved='" . $closedate . "', closingverdict='" . mysqli_real_escape_string($verdict) . "' WHERE id='" . trim($id) . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        $results = $query;
        mysqli_close($dbconnection);
        return $results;
    }

    function delete_complaint($id)
    {
        global $configfile;
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbcoctable");
        if (empty($id)) {
            $results = "failed, message id can not be blank";
            return $results;
            exit;
        }
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("DELETE FROM " . trim($dbtable) . " WHERE id='" . trim($id) . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        $results = $query;
        mysqli_close($dbconnection);
        return $results;
    }

    function update_complaint_modnotes($notes, $id)
    {
        global $configfile;
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbcoctable");
        if (empty($notes)) {
            $results = "failed, notes can not be blank";
            return $results;
            exit;
        }
        if (empty($id)) {
            $results = "failed, id can not be blank";
            return $results;
            exit;
        }
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("UPDATE " . trim($dbtable) . " SET modnotes='" . mysqli_real_escape_string(trim($notes)) . "' WHERE id='" . trim($id) . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        $results = $query;
        mysqli_close($dbconnection);
        return $results;
    }

    function claim_complaint($moderator, $id)
    {
        global $configfile;
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbcoctable");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("UPDATE " . trim($dbtable) . " SET moderator='" . trim($moderator) . "' WHERE id='" . trim($id) . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        $results = $query;
        mysqli_close($dbconnection);
        return $results;
    }

    function add_abusecomplaint($from, $about, $dtofproblem, $gamenumber, $summary, $message, $screenshoturl)
    {
        global $configfile;
        $results = "unknown";
        if (empty($from)) {
            $results = "failed, from user name can not be blank";
            return $results;
            exit;
        }
        if (empty($about)) {
            $results = "failed, about user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dtofproblem)) {
            $results = "failed, date / time of problem can not be blank";
            return results;
            exit;
        }
        if (empty($summary)) {
            $results = "failed, summary can not be blank";
            return $results;
            exit;
        }
        if (empty($message)) {
            $results = "failed, message can not be blank";
            return $results;
            exit;
        }
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbusertable");
        $dbcoctable = get_config_value($configfile, "dbcoctable");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbcoctable)) {
            $results = "failed, database cockatrice code of conduct report table can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("SELECT name FROM " . trim($dbtable) . " WHERE name='" . trim($about) . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        $row = mysqli_fetch_array($query);
        if (strtolower(trim($about)) == strtolower(trim($row['name']))) {
            $reguser = "1";
        } else {
            $reguser = "0";
        }
        $query = mysqli_query("INSERT into " . trim($dbcoctable) . " (userfrom,userabout,aboutreguser,dtofproblem,gamenumber,briefdescription,message,datereported,screenshoturl) VALUES ('" . mysqli_real_escape_string(trim($from)) . "','" . mysqli_real_escape_string(trim($about)) . "','" . mysqli_real_escape_string($reguser) . "','" . mysqli_real_escape_string(trim(mysqli_real_escape_string($dtofproblem))) . "','" . mysqli_real_escape_string(trim($gamenumber)) . "','" . mysqli_real_escape_string(trim(mysqli_real_escape_string($summary))) . "','" . trim(mysqli_real_escape_string($message)) . "','" . date("Y-m-d H:i:s") . "','" . trim(mysqli_real_escape_string($screenshoturl)) . "')");
        $results = $query;
        mysqli_close($dbconnection);
        return $results;
    }

    function send_email($username, $subject, $message)
    {
        global $configfile;
        $results = "unknown";
        if (empty($username)) {
            $results = "failed, user name can not be blank";
            return $results;
            exit;
        }
        if (empty($subject)) {
            $results = "failed, subject can not be blank";
            return $results;
            exit;
        }
        if (empty($message)) {
            $results = "failed, message can not be blank";
            return $results;
            exit;
        }
        $usersemailaddress = get_user_data($username, "email");
        if (!empty($usersemailaddress)) {
            $message = wordwrap($message, 70, "\r\n");
            $results = mail($usersemailaddress, $subject, $message);
        } else {
            $results = "warning, user does not have an email address associated with account";
        }
        return $results;
    }

    function get_registered_usercount()
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbusertable");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("SELECT count(*) FROM " . trim($dbtable));
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        while ($row = mysqli_fetch_array($query)) {
            $results = $row['count(*)'];
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function locate_username_byid($userid)
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbusertable");
        if (empty($userid)) {
            $results = "failed, user id can not be blank";
            return $results;
            exit;
        }
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("SELECT * FROM " . trim($dbtable) . " WHERE id='" . trim($userid) . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        $row = mysqli_fetch_array($query);
        $results = $row['name'];
        mysqli_close($dbconnection);
        return $results;
    }

    function locate_username_byemail($emailaddress)
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbusertable");
        if (empty($emailaddress)) {
            $results = "failed, email address can not be blank";
            return $results;
            exit;
        }
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("SELECT * FROM " . trim($dbtable) . " WHERE email='" . trim($emailaddress) . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        $row = mysqli_fetch_array($query);
        $results = $row['name'];
        mysqli_close($dbconnection);
        return $results;
    }

    function dont_allow_blank($database_value)
    {
        if (strlen($database_value) < 1) {
            return " ";
        } else {
            return $database_value;
        }
    }

    function get_user_data($username, $date_to_collect)
    {
        global $configfile;

        $db_server = get_config_value($configfile, "dbserver");
        $db_username = get_config_value($configfile, "dbusername");
        $db_password = get_config_value($configfile, "dbpassword");
        $db_name = get_config_value($configfile, "dbname");
        $db_table = get_config_value($configfile, "dbusertable");
        $db_connection = connect_to_database($db_server, $db_username, $db_password, $db_name);

        $query =
            mysqli_query(
                $db_connection,
                "SELECT * FROM " .
                         trim($db_table) .
                         " WHERE LOWER(name)='"
                         . mysqli_real_escape_string($db_connection, strtolower(trim($username))) . "'"
            ) or die(mysqli_error());

        $row = mysqli_fetch_array($query);

        $date_to_collect = strtolower(trim($date_to_collect));

        mysqli_close($db_connection);
        return $row["$date_to_collect"];
    }

    function add_user($username, $password, $email)
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbusertable");
        $requireverification = get_config_value($configfile, "requireaccountverification");
        $encryptedpassword = crypt_password($password, '');
        $registrationdate = date("Y-m-d H:i:s");
        $emailexists = check_if_email_exists($email);
        if (empty($username)) {
            $results = "failed, user name can not be blank";
            return $results;
            exit;
        }
        if (empty($password)) {
            $results = "failed, password can not be blank";
            return $results;
            exit;
        }
        if (empty($email)) {
            $results = "failed, email can not be blank";
            return $results;
            exit;
        }
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($registrationdate)) {
            $results = "failed, registration date can not be blank";
            return $results;
            exit;
        }
        if (empty($requireverification)) {
            $results = "failed, registration requirement can not be blank";
            return $results;
            exit;
        }

        // account restriction filters
        if (strpos(strtolower($email), '@trbvm.com') !== false) {
            $results = "failed, email address is not allowed";
            return $results;
            exit;
        }
        if (strpos(strtolower($email), 'bardnarson') !== false) {
            $results = "failed, email address is not allowed";
            return $results;
            exit;
        }
        if (strpos(strtolower($email), 'barnarson') !== false) {
            $results = "failed, email address is not allowed";
            return $results;
            exit;
        }
        if (strpos(strtolower($email), 'ryanbraundunn') !== false) {
            $results = "failed, email address is not allowed";
            return $results;
            exit;
        }

        if (empty($emailexists)) {
            $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
            if (strpos(strtolower($dbconnection), "fail") !== false) {
                $results = strtolower($dbconnection);
                return $results;
                exit;
            }
            if (strtolower($requireverification) == "yes") {
                $query = mysqli_query("INSERT INTO " . trim($dbtable) . " (name,password_sha512,email,active,registrationDate) VALUES ('" . mysqli_real_escape_string(trim($username)) . "','" . trim($encryptedpassword) . "','" . mysqli_real_escape_string(trim($email)) . "',0,'" . $registrationdate . "')");
                if ($query) {
                    generate_accountverificationfile($username);
                    // $results = "Successfully created user account.<br>An email verification will be sent shortly.<br>";
                    $results = 'Your account has been created and is set to disabled.
                    An activation link has been sent to the email address you entered.<br>
                    Please following the activation link sent in the email to enable your account.
                    Activation emails can take up to 10 minutes to receive so please be patient.<br>
                    If your activation email does not arrive please contact a moderator and ask to activate your account or use our contact us link on our main site.<br><br>
                    If you do not see a link in your email, please view source (or show original) of the email.<br>
                    Chances are your email client/provider is incorrectly identifying the email as a phishing email and hiding the link for security reasons.';
                } else {
                    $results = "failed, " . mysqli_error();
                }
            } else {
                $query = mysqli_query("INSERT INTO " . trim($dbtable) . " (name,password_sha512,email,active,registrationDate) VALUES ('" . mysqli_real_escape_string(trim($username)) . "','" . trim($encryptedpassword) . "','" . mysqli_real_escape_string(trim($email)) . "',1,'" . $registrationdate . "')");
                if ($query) {
                    $results = "Successfully created user account.";
                } else {
                    $results = "failed, " . mysqli_error();
                }
            }
            mysqli_close($dbconnection);
            return $results;
        } else {
            $results = "Email address already in use.";
            return $results;
        }
    }

    function delete_user($username)
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbusertable");
        if (empty($username)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("DELETE FROM " . trim($dbtable) . " WHERE name='" . trim($username) . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function update_user_table($username, $tablecolumn, $tablecolumnvalue)
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbusertable");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($username)) {
            $results = "failed, user name can not be blank";
            return $results;
            exit;
        }
        if (empty($tablecolumn)) {
            $results = "failed, table column name can not be blank";
            return $results;
            exit;
        }
        if (strtolower($tablecolumn) == "password_sha512") {
            $tablecolumnvalue = crypt_password($tablecolumnvalue, '');
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        if (strtolower($tablecolumn) == "country") {
            $tablecolumnvalue = strtolower($tablecolumnvalue);
        }
        if (strtolower($tablecolumn) == "gender") {
            $tablecolumnvalue = strtolower($tablecolumnvalue);
        }
        $query = mysqli_query("UPDATE " . trim($dbtable) . " SET " . trim($tablecolumn) . "='" . mysqli_real_escape_string(trim($tablecolumnvalue)) . "' WHERE name='" . mysqli_real_escape_string(trim($username)) . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function add_ban($username, $ipaddress, $modname, $starttime, $duration, $reason, $displayreason)
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbusertable = get_config_value($configfile, "dbusertable");
        $dbtable = get_config_value($configfile, "dbbantable");
        if (empty($modname)) {
            $results = "failed, moderator name can not be blank";
            return $results;
            exit;
        }
        if (empty($starttime)) {
            $starttime = date("Y-m-d H:i:s");
        }
        if (empty($reason)) {
            $results = "failed, reason can not be blank";
            return $results;
            exit;
        }
        if (empty($displayreason)) {
            $displayreason = $reason;
        }
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusertable)) {
            $results = "failed, database user table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("SELECT id FROM " . $dbusertable . " WHERE name='" . $modname . "'");
        $row = mysqli_fetch_array($query);
        if (!$row) {
            $results = "failed, moderator not found";
            return $results;
            exit;
        }
        $adminid = $row['id'];
        $query = mysqli_query("INSERT INTO " . trim($dbtable) . "(user_name,ip_address,id_admin,time_from,minutes,reason,visible_reason) VALUES ('" . $username . "','" . $ipaddress . "','" . $adminid . "','" . $starttime . "'," . $duration . ",'" . mysqli_real_escape_string($reason) . "','" . mysqli_real_escape_string($displayreason) . "')");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function delete_ban($username, $timestamp)
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbbantable");
        if (empty($username)) {
            $results = "failed, user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($timestamp)) {
            $results = "failed, time stamp can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        if (trim(strtolower($timestamp)) == "all") {
            $query = mysqli_query("DELETE FROM " . trim($dbtable) . " WHERE user_name='" . trim($username) . "'");
        } else {
            $query = mysqli_query("DELETE FROM " . trim($dbtable) . " WHERE user_name='" . trim(mysqli_real_escape_string($username)) . "' AND time_from='" . trim($timestamp) . "'");
        }
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function add_servermessage($serverid, $timestamp, $message)
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbmessagetable");
        if (empty($serverid)) {
            $serverid = get_config_value($configfile, "serverid");
        }
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($serverid)) {
            $results = "failed, id of server can not be blank";
            return $results;
            exit;
        }
        if (empty($message)) {
            $results = "failed, server message can not be blank";
            return $results;
            exit;
        }
        if (empty($timestamp)) {
            $timestamp = date("Y-m-d H:i:s");
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("INSERT INTO " . trim($dbtable) . " (id_server,timest,message) VALUES (" . trim($serverid) . ",'" . trim($timestamp) . "','" . trim(mysqli_real_escape_string($message)) . "')");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function delete_servermessage($serverid, $timestamp)
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbmessagetable");
        if (empty($id)) {
            $serverid = get_config_value($configfile, "serverid");
        }
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($timestamp)) {
            $results = "failed, time stamp can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("DELETE FROM " . trim($dbtable) . " WHERE timest='" . trim($timestamp) . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function delete_dbrows($count, $dbtable)
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($count)) {
            $results = "failed, number of rows to delete can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        if (trim(strtolower($count)) == "all") {
            $query = mysqli_query("DELETE FROM " . trim($dbtable));
        } else {
            $query = mysqli_query("DELETE FROM " . trim($dbtable) . " limit " . $count);
        }
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function get_playercount()
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbsessiontable");
        $serverid = get_config_value($configfile, "serverid");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query($dbconnection, "SELECT count(*) FROM " . trim($dbtable) . " WHERE end_time IS NULL and id_server = " . $serverid);
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        while ($row = mysqli_fetch_array($query)) {
            $results = $row['count(*)'];
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function get_modcount()
    {
        global $configfile;
        $results = "unknown";
        $count = 0;
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbsessiontable");
        $dbusertable = get_config_value($configfile, "dbusertable");
        $serverid = get_config_value($configfile, "serverid");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusertable)) {
            $results = "failed, database users table can not be blank";
            return results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("SELECT name  FROM " . trim($dbusertable) . " WHERE admin != 0");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        while ($row = mysqli_fetch_array($query)) {
            $query2 = mysqli_query("SELECT * FROM " . trim($dbtable) . " WHERE user_name = '" . $row['name'] . "' AND end_time is NULL and id_server = " . $serverid);
            while ($row2 = mysqli_fetch_array($query2)) {
                $count = $count + 1;
            }
        }
        mysqli_close($dbconnection);
        $results = $count;
        return $results;
    }

    function get_replaycount()
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbreplaytable");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("SELECT count(*) FROM " . trim($dbtable));
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        while ($row = mysqli_fetch_array($query)) {
            $results = $row['count(*)'];
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function get_uptimedatacount()
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbuptimetable");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("SELECT count(*) FROM " . trim($dbtable));
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        while ($row = mysqli_fetch_array($query)) {
            $results = $row['count(*)'];
        }
        mysqli_close($dbconnection);
        return $results;
    }


    function get_session_data($username, $data, $state)
    {
        global $configfile;
        $results = "unknown";
        if (empty($username)) {
            $results = "failed, user name can not be blank";
            return $results;
            exit;
        }
        if (empty($data)) {
            $results = "failed, data to collect can not be blank";
            return $results;
            exit;
        }
        if (empty($state)) {
            $state = "offline";
        }
        $dbserv = get_config_value($configfile, "dbserver");
        if (strpos(strtolower($dbserver), "fail") !== false) {
            $results = strtolower($dbserver);
            return $results;
            exit;
        }
        $dbuser = get_config_value($configfile, "dbusername");
        if (strpos(strtolower($dbuser), "fail") !== false) {
            $results = strtolower($dbuser);
            return $results;
            exit;
        }
        $dbpass = get_config_value($configfile, "dbpassword");
        if (strpos(strtolower($dbpass), "fail") !== false) {
            $results = strtolower($dbpass);
            return $results;
            exit;
        }
        $dbname = get_config_value($configfile, "dbname");
        if (strpos(strtolower($dbname), "fail") !== false) {
            $results = strtolower($dbname);
            return $results;
            exit;
        }
        $dbtable = get_config_value($configfile, "dbsessiontable");
        if (strpos(strtolower($dbtable), "fail") !== false) {
            $results = strtolower($dbtable);
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserv, $dbuser, $dbpass, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("SELECT * from " . $dbtable  . " where user_name='" . $username . "' ORDER BY id DESC limit 1");
        if (!query) {
            $results = "failed, " . mysqli_error();
            return $results;
            exit;
        }
        $row = mysqli_fetch_array($query);
        if (!row) {
            $results = "failed, unable to locate user session data information (UserNotLoggedIn)";
            return $results;
            exit;
        }
        switch (strtolower($data)) {
            case 'id':
                $results = $row['id'];
                break;
                        case 'user_name':
                                $results = $row['user_name'];
                                break;
                        case 'id_server':
                                $results = $row['id_server'];
                                break;
                        case 'ip_address':
                                $results = $row['ip_address'];
                                break;
                        case 'start_time':
                                $results = $row['start_time'];
                                break;
                        case 'end_time':
                                $results = $row['end_time'];
                                break;
                        default:
                                $results = 'Failed, unknown database user data type ' . $datatocollect;
                }
        mysqli_close($dbconnection);
        return $results;
    }

    function generate_accountverificationfile($username)
    {
        global $configfile;
        $tempfolder = get_config_value($configfile, "tempregverfilefolder");
        $filename = generateRandomString();
        $usersemailaddress = get_user_data($username, "email");
        if (empty($username)) {
            $results = "failed, user name can not be blank";
            return $results;
            exit;
        }
        if (empty($tempfolder)) {
            $results = "failed, temp registration verification folder name can not be blank";
            return $results;
            exit;
        }
        if (empty($filename)) {
            $results = "failed, temp file name can not be blank";
            return $results;
            exit;
        }
        if (empty($usersemailaddress)) {
            $results = "failed, email address can not be blank";
            return $results;
            exit;
        }
        file_put_contents($tempfolder . "/" . $filename . ".php", "<!DOCTYPE html>\n", LOCK_EX);
        file_put_contents($tempfolder . "/" . $filename . ".php", "<head>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "<title>Account Verification</title>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "</head>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "<html>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "<body>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "<?php\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "require '../.config_commonfunctions';\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "\$configfile = '../.config';\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "\$activateresults = update_user_table('" . $username . "','active','1');\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "echo '<center>';\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "if (\$activateresults = 'success'){\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "echo 'Your account has now been verified, you should be able to log into the servatrice server using your registered account name.<br>';\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "} else {\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "echo 'Account activation failed, please contact us to activate your account';\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "}\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "echo '</center>';\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "?>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "</body>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "</html>\n", FILE_APPEND);

        file_put_contents($tempfolder . "/" . $filename . ".mail", "To: " . $usersemailaddress . "\n", LOCK_EX);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "From: do-not-reply@woogerworks.com\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "Subject: Cockatrice Account Verification\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "Click the following link to activate your cockatrice user account:\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "http://cockatrice.woogerworks.com/registration/" . $filename . ".php\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "(activation link will only be available till midnight EST time zone)\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "Remember, if you do not see an activation link please check your phishing filter settings.\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "For more information on how to for the more major email providers see our main site.\n", FILE_APPEND);
    }

    function generateRandomString($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }

    function check_if_email_exists($emailaddress)
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbusertable");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("SELECT count(*) FROM " . trim($dbtable) . " where email='" . $emailaddress . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        while ($row = mysqli_fetch_array($query)) {
            $results = $row['count(*)'];
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function check_if_user_exists($username)
    {
        global $configfile;
        $results = "unknown";
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbusertable");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("SELECT count(*) FROM " . trim($dbtable) . " where name='" . $username . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        while ($row = mysqli_fetch_array($query)) {
            $results = $row['count(*)'];
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function generate_forgotpasswordfile($usersemailaddress)
    {
        if (empty($usersemailaddress)) {
            $results = "failed, users email address can not be blank";
            return $results;
            exit;
        }
        global $configfile;
        $tempfolder = get_config_value($configfile, "tempregverfilefolder");
        $filename = generateRandomString();
        $username = locate_username_byemail($usersemailaddress);
        $results = 'success';
        if (empty($username)) {
            $results = "failed, user name can not be blank";
            return $results;
            exit;
        }
        if (empty($tempfolder)) {
            $results = "failed, temp registration verification folder name can not be blank";
            return $results;
            exit;
        }
        if (empty($filename)) {
            $results = "failed, temp file name can not be blank";
            return $results;
            exit;
        }

        file_put_contents($tempfolder . "/" . $filename . ".php", "<!DOCTYPE html>\n", LOCK_EX);
        file_put_contents($tempfolder . "/" . $filename . ".php", "<head>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "<title>Account Password Recovery</title>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "</head>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "<html>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "<body>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "<?php\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "require '../.config_commonfunctions';\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "\$configfile = '../.config';\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "\$results = update_user_table('" . $username . "','password_sha512','" . $username . "');\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "echo '<center>';\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "if (\$results = 'success'){\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "echo 'Your account password has been reset to your username. Please log in to the account management web interface and update your password.<br>';\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "} else {\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "echo 'Password reset failed, please contact us</a> to change your password.<br>';\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "}\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "echo '</center>';\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "?>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "</body>\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".php", "</html>\n", FILE_APPEND);

        file_put_contents($tempfolder . "/" . $filename . ".mail", "To: " . $usersemailaddress . "\n", LOCK_EX);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "From: do-not-reply@woogerworks.com\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "Subject: Cockatrice Account Recovery\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "Click the following link to reset your cockatrice user account password:\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "http://cockatrice.woogerworks.com/registration/" . $filename . ".php\n", FILE_APPEND);
        file_put_contents($tempfolder . "/" . $filename . ".mail", "(activation link will only be available till midnight EST time zone)\n", FILE_APPEND);
        return $results;
    }

    function logfailedattempt($username, $reason)
    {
        global $configfile;
        $results = 'success';
        $file = get_config_value($configfile, "failedloginattemptlog");
        if (empty($username)) {
            $results = "failed, user name can not be blank";
            return $results;
            exit;
        }
        if (file_exists($file)) {
            file_put_contents($file, $username . "," . $reason . "," . date('Y/m/d H:i:s') . "<br>\n", FILE_APPEND);
        } else {
            file_put_contents($file, "<?php require '.auth_modsession' ?>\n", LOCK_EX);
            file_put_contents($file, $username . "," . $reason . "," . date('Y/m/d H:i:s') . "<br>\n", FILE_APPEND);
        }
        return $results;
    }

    function get_file_linecount($file)
    {
        $linecount = 0;
        $handle = fopen($file, "r");
        while (!feof($handle)) {
            $line = fgets($handle);
            $linecount++;
        }
        fclose($handle);
        return $linecount;
    }

    function add_ipban($ipaddress)
    {
        global $configfile;
        $results = 'success';
        $filename = generateRandomString();
        $tempfolder = get_config_value($configfile, "tempregverfilefolder");
        if (empty($ipaddress)) {
            $results = "failed, ip address can not be blank";
            return $results;
            exit;
        }
        if (empty($filename)) {
            $results = "failed, file name can not be blank";
            return $results;
            exit;
        }
        if (empty($tempfolder)) {
            $results = "failed, temp folder can not be blank";
            return $results;
            exit;
        }
        file_put_contents($tempfolder . "/" . $filename . ".fwrule", $ipaddress, LOCK_EX);
        return $results;
    }

    function add_room($id, $name, $descript, $autoj, $joinm)
    {
        global $configfile;
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbroomtable");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($id)) {
            $results = "failed, room id can not be blank";
            return $results;
            exit;
        }
        if (empty($name)) {
            $results = "failed, room name can not be blank";
            return $results;
            exit;
        }
        if (empty($descript)) {
            $results = "failed, room description can not be blank";
            return $results;
            exit;
        }
        if (empty($autoj)) {
            $results = "failed, room auto join value can not be blank";
            return $results;
            exit;
        }
        if (empty($joinm)) {
            $results = "failed, room join message can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("INSERT INTO " . $dbtable . " (id,name,descr,auto_join,join_message) VALUES ('" . trim(mysqli_real_escape_string($id)) . "','" . trim(mysqli_real_escape_string($name)) . "','" . trim(mysqli_real_escape_string($descript)) . "','" . trim(mysqli_real_escape_string($autoj)) . "','" . trim(mysqli_real_escape_string($joinm)) . "')");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function add_game_type($id, $gamename)
    {
        global $configfile;
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbgametypetable");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($id)) {
            $results = "failed, room id can not be blank";
            return $results;
            exit;
        }
        if (empty($gamename)) {
            $results = "failed, game name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("INSERT INTO " . $dbtable . " (id_room,name) VALUES ('" . trim(mysqli_real_escape_string($id)) . "','" . trim(mysqli_real_escape_string($gamename)) . "')");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function delete_room($id)
    {
        global $configfile;
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbroomtable");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($id)) {
            $results = "failed, room id can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("DELETE FROM " . $dbtable . " WHERE id='" . $id . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        mysqli_close($dbconnection);
        return $results;
    }

    function delete_gametype($id, $name)
    {
        global $configfile;
        $dbserver = get_config_value($configfile, "dbserver");
        $dbusername = get_config_value($configfile, "dbusername");
        $dbpassword = get_config_value($configfile, "dbpassword");
        $dbname = get_config_value($configfile, "dbname");
        $dbtable = get_config_value($configfile, "dbgametypetable");
        if (empty($dbserver)) {
            $results = "failed, database server can not be blank";
            return $results;
            exit;
        }
        if (empty($dbusername)) {
            $results = "failed, database user name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbpassword)) {
            $results = "failed, database user name password can not be blank";
            return $results;
            exit;
        }
        if (empty($dbname)) {
            $results = "failed, database name can not be blank";
            return $results;
            exit;
        }
        if (empty($dbtable)) {
            $results = "failed, database table name can not be blank";
            return $results;
            exit;
        }
        if (empty($id)) {
            $results = "failed, room id can not be blank";
            return $results;
            exit;
        }
        if (empty($name)) {
            $results = "failed, game name can not be blank";
            return $results;
            exit;
        }
        $dbconnection = connect_to_database($dbserver, $dbusername, $dbpassword, $dbname);
        if (strpos(strtolower($dbconnection), "fail") !== false) {
            $results = strtolower($dbconnection);
            return $results;
            exit;
        }
        $query = mysqli_query("DELETE FROM " . $dbtable . " WHERE id_room='" . $id . "' AND name='" . $name . "'");
        if ($query) {
            $results = "success";
        } else {
            $results = "failed, " . mysqli_error();
        }
        mysqli_close($dbconnection);
        return $results;
    }
