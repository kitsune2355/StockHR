<?php
	error_reporting(E_ALL ^ E_NOTICE);
	@session_start();
	$buttoncontorno = $_GET['buttoncontorno'];
	$_SESSION['sess_buttoncontorno']= $buttoncontorno;
	$chk_menu = $_GET['chk_menu'];
	if($buttoncontorno==3){
?>
		<script language="javascript">
		window.location.href="../tax_list.php"
		<?php //exit(); ?>
		</script>
<?php
	}elseif($buttoncontorno==2){
?>
		<script language="javascript">
		window.location.href="../repair_list.php?active=6"
		<?php //exit(); ?>
		</script>
<?php
	}
?>
		<script language="javascript">
			window.location.href="../index.php?chk_menu=WrEmOpdEdsEm"
		</script>