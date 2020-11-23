<!DOCTYPE html>
<head>
<title>Servatrice Administrator</title>
</head>
<html>
	<body>
		<?php
			require '.auth_modsession';
			require '.config_commonfunctions';
			global $configfile;
		?>
		<table align="center" border="1" cellpadding="5">
			<tr>
				<td align="center" colspan="5"><a href="portal_complaintmanagement.php">Complaint Management Menu</a></td>
				<td align="center"><a href="logout.php">Logout</a></td>
			</tr>
			<tr>
				<td>ID</td>
				<td>From</td>
				<td>About</td>
				<td>Description</td>
				<td>Moderator</td>
				<td></td>
			</tr>
			<form action="viewcomplaintdetails.php" method="post">
				<?php
					$nonresolved = "0000-00-00%";
					$complaintcount = 0;
					$dbserv = get_config_value($configfile,"dbserver");
					$dbuser = get_config_value($configfile,"dbusername");
					$dbpass = get_config_value($configfile,"dbpassword");
					$dbname = get_config_value($configfile,"dbname");
					$dbtable = get_config_value($configfile,"dbcoctable");
					if (strpos(strtolower($dbserv),"fail") !== false){ echo '<tr><td align="center" colspan="2">Failed to connect to datbase, database server name can not be blank</td></tr>'; exit; }
					if (strpos(strtolower($dbuser),"fail") !== false){ echo '<tr><td align="center" colspan="2">Failed to connect to datbase, database user name can not be blank</td></tr>'; exit; }
					if (strpos(strtolower($dbpass),"fail") !== false){ echo '<tr><td align="center" colspan="2">Failed to connect to datbase, database password can not be blank</td></tr>'; exit; }
					if (strpos(strtolower($dbname),"fail") !== false){ echo '<tr><td align="center" colspan="2">Failed to connect to datbase, database name can not be blank</td></tr>'; exit; }
					if (strpos(strtolower($dbtable),"fail") !== false){ echo '<tr><td align="center" colspan="2">Failed to connect to datbase, database table name can not be blank</td></tr>'; exit; }
					$dbconnection = connect_to_database($dbserv,$dbuser,$dbpass,$dbname);
					if (strpos(strtolower($dbconnection),"fail") !== false){ $results = strtolower($dbconnection); return $results; exit; }
					$query = mysqli_query("SELECT * FROM " . $dbtable . " WHERE dateresolved like '" . $nonresolved . "'" );
	              			if (!query){ $results = "failed, " . mysqli_error(); return $results; exit; }
					while ($row = mysqli_fetch_array($query)){
						$complaintcount = $complaintcount + 1;
						echo '<tr>';
						echo '<td>' . $row['id'] . '</td>';
						echo '<td>' . $row['userfrom'] . '</td>';
						echo '<td>' . $row['userabout'] . '</td>';
						echo '<td>' . $row['briefdescription'] . '</td>';
						echo '<td>' . $row['moderator'] . '</td>';
						echo '<td><input type="radio" name="messageid" value="' . $row['id'] . '" /></td>';
						echo '</tr>';
					}
					echo '<tr><td colspan="6" align="right">' . $complaintcount . ' Total unresolved issues</td></tr>';	
					mysqli_close($dbconnection);
				?>
				<tr><td align="center" colspan="6"><input type="submit" value="View Complaint Details" /></td></tr>
			</form>
		</table>
	</body>
</html>
