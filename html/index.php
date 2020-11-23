<!DOCTYPE html>
<head>
<title>Servatrice Administrator</title>
</head>
<html>
	<body>
		<?php

            require __DIR__ . '/vendor/autoload.php';

            use ServatriceAWUI;

            $awui = new Core();

            // $version = config.get(version)
            // $banner = config.get(banner)

        ?>
		
		<table border="1" align="center" cellpadding="5">
			<tr align="center">
				<td><a href="loginpage.php">Account Log-in</a></td>
				<td><a href="statistics.php">Statistics</a></td>
				<td><a href="registrationpage.php">Registration</a></td>
				<td><a href="codeofconduct.html">Code of Conduct</a></td>
			</tr>
			<tr><td colspan="4" align="center"><?php if (!empty($banner)) {
            echo trim($banner) . '<br>';
        } ?></td></tr>
			<tr><td colspan="4"><?php echo '<font size="1">v.' . trim($version) . '</font>'; ?></td></tr>	
			<tr><td colspan="2" align="center"><a href="changeusername.php"><i>Change Username</i></td><td colspan="2" align="center"><a href="forgotpassword.php"><i>Forgot Password?</i></a></td></tr>
		</table>
	</body>
</html>
