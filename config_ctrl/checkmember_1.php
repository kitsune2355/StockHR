<?php
	include "connect.php";
	error_reporting(E_ALL ^ E_NOTICE);
	@session_start();
	ob_start();
	$user_login		= $_POST['LogInName'];
	$pass_login		= $_POST['LogInPassWord'];
	$LoginTypeH		= $_POST['LoginTypeH'];
	$server_id		= $_POST['server_id'];
	$server_id_tmp	= $_POST['server_id'];
	 $one = '1';
	 $sql="SELECT tbUsr.*
		  FROM tb_user AS tbUsr
		  Where tbUsr.user_id 		= '$user_login'  
		  "; 
	echo 
	$query=mysqli_query($connect,$sql) or die(mysqli_error($connect));
	$num_rows=mysqli_num_rows($query);
	if ($num_rows>=1){ 
	$_SESSION['sess_id']				='123'; 
	 ?>
	<script language="javascript">
		window.location.href="../index_mng.php";
	</script>  
	<?php
	}else{
	?>
	<script language="javascript">
		alert("Cannot enter the system");
		window.location.href="../login_mng.php";
	</script>
		
<?php 
	}
	@session_start();
?>