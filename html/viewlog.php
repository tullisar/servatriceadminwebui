<!DOCTYPE HTML>
<head>
	<title>Servatrice Administrator</title>
	<meta name="author" content="Zach H (ZeldaZach)">
	<style>
		td {text-align:center;}
	</style>

</head>
<body>
	<?php
		require '.auth_modsession';
		require '.config_commonfunctions';
		?>
	<center>
	<form method="POST">
	<table border="1">
		<tr>
                        <td align="center"><a href="portal_servermanagement.php">Server Management Menu</a></td>
                	<td align="center"><a href="logout.php">Logout</a></td>
                </tr>
		<tr>
			<td align="center">Find By Username</td>
			<td align="center"><input type="text" name="username" placeHolder="Username" value="<?=$_REQUEST['username']?>" style="width:200px; text-align: center;"></td>
		</tr>
		<tr>
			<td align="center">Find By IP Address</td>
			<td align="center"><input type="text" name="ip_address" placeHolder="127.0.0.1" value="<?=$_REQUEST['ip_address']?>" style="width:200px; text-align: center;"></td>
		</tr>
		<tr>
			<td align="center">Find By Game ID</td>
			<td align="center"><input type="text" name="game_id" placeHolder="Game ID" value="<?=$_REQUEST['game_id']?>" style="width:200px; text-align: center;"></td>
		</tr>
		<tr>
			<td align="center">Find By Game Name</td>
			<td align="center"><input type="text" name="game_name" placeHolder="Game Name" value="<?=$_REQUEST['game_name']?>" style="width:200px; text-align: center;"></td>
		</tr>
		<tr>
			<td align="center">Log Location</td>
			<td>
				<div style="text-align:left; padding-left:30%">
					<input type="checkbox" name="type_of_chat[0]" value="room">Main Room<br/>
					<input type="checkbox" name="type_of_chat[1]" value="game">Game Rooms<br/>
					<input type="checkbox" name="type_of_chat[2]" value="chat">Private Chat
				</div>
			</td>
		</tr>
		<tr>
			<td align="center">Date Range</td>
			<td>
				<div style="text-align:left; padding-left:30%">
					<input type="radio" name="from_date" value="week">Past 7 days<br/>
					<input type="radio" name="from_date" value="day">Today<br/>
					<input type="radio" name="from_date" value="hour">Last Hour
				</div>
			</td>
		</tr>
		<tr>
			<td align="center">Maximum Results</td>
			<td><input type="text" name="max_results" placeHolder="2000" value="<?=$_REQUEST['max_results']?>" style="width:200px; text-align: center;"></td>
		</tr>
		<tr>
			<td colspan="2" style="width:400px">
				All fields are optional.<br/>The more information you put in, the more specific your results will be.<br/>
			</td>
		</tr>
		<tr>
			<td colspan="2" style="width:400px">
				<input type="submit" value="Get User Logs" style="width:150px">
			</td>
		</tr>
	</table>
	</form>
	<br/><br/>
<?php
	if (count($_REQUEST) > 0)
	{
		build_log_table();
	}
?>
</center>
</body>
</html>
