<?php
	error_reporting(E_ALL ^ E_NOTICE);
	@session_start();
	
	$user_login		= $_POST['LogInName'];
	$pass_login		= $_POST['LogInPassWord'];
	$LoginTypeH		= $_POST['LoginTypeH'];
	$server_id		= $_POST['server_id'];
	$server_id_tmp	= $_POST['server_id']; 
	$db_short_name	= '81';
	if ($user_login!="" and $pass_login!=""){
		$SERVER_HOST = $_SERVER['HTTP_HOST'];
		if($SERVER_HOST=="localhost") {
			$server_id = 'job_'.$db_short_name; 
	
		}else{
			$server_id = 'developh_amnew'; 
		}
		include "connect.php";
		$pass_login_md5 = md5(md5(md5($pass_login)));

		$sql="SELECT tbUsr.*
			  FROM tb_member AS tbUsr
			  Where tbUsr.member_username 		= '$user_login' 
			  And tbUsr.member_password 	= '$pass_login_md5'
			  "; 
		$query=mysqli_query($connect,$sql) or die(mysqli_error($connect));
		$num_rows=mysqli_num_rows($query);
		if ($num_rows>=1){
			$result=mysqli_fetch_array($query) or die(mysqli_error($connect));
			$_SESSION['sess_id']				=session_id(); 
			$_SESSION['sess_member_id']			=$result["member_id"]; 
			$_SESSION['sess_member_status']			=$result["member_status"];  
			$_SESSION['sess_user_name']			=$result["user_name"];
			$_SESSION['sess_user_fname']		=$result["user_fname"];
			$_SESSION['sess_user_password']		=$result["user_password"];
			$_SESSION['sess_user_level']		=$result["user_level"];
			$_SESSION['sess_user_email']		=$result["user_email"];
			$_SESSION['sess_user_dep_id']		=$result["user_dep_id"];
			//$_SESSION['sess_user_shop']			=$result["user_shop"];
			$_SESSION['sess_status_login']		=$result["user_status_login"];
			$_SESSION['sess_user_position']		=$result["user_position"];
			$_SESSION['sess_position_level']	=$result["user_position_level"];
			$_SESSION['sess_chk_cashire']		=$result["user_chk_cashire"];
			$_SESSION['sess_chk_tax']			=$result["user_chk_tax"];
			$_SESSION['sess_chk_it']			=$result["user_chk_it"];
			$_SESSION['sess_chk_finance']		=$result["user_chk_finance"];
			$_SESSION['sess_SYSTEM_CTRL']		=$db_short_name;
			$_SESSION['sess_server_id']			=$server_id;
			$_SESSION['sess_tmp_usr_id']		=date('His');
			$_SESSION['sess_SERVER_HOST']		=$SERVER_HOST;
			$_SESSION['session_time']=time();  
				?>
				<?php  
				if(($_SESSION['sess_member_status'] == '2')){
					
				?>
				<script language="javascript">
					window.location.href="../index.php"
				</script>
				<?php
				}else{
				?>
				<script language="javascript">
						window.location.href="../config_ctrl/logout.php"
				</script>
				<?php
				}
				?>
			<?php
		}else{
			?>
			<script language="javascript">
				window.location.href="../login_member.php?wrongLogin=Yes";
			</script>
			<?php
		}
	}else{
		?>
		<script language="javascript">
			window.location.href="../login_member.php?wrongLogin=Yes";
		</script>
		<?php
	}
?>