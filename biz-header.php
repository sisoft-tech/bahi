<style>
nav{
  border-bottom: 2px solid red;
}
#header_logo{
	width:70px;
	height: 70px;
}
.logo_text{
  padding : 1px 50px;
}

@media screen and (max-width: 600px) {
   .logo_text{
   	display: none;
   }
}
</style>


 
<nav style="background:#5bc0de; color:#fff ; ">
<div class="container-fluid">
<div class="row">
<div class="col-md-2">
Euphoria BAHI DESKTOP
</div> 

<div class="col-md-2"> </div>
<div class="col-md-1"> <a class="nav-link" href="biz-mybusiness-manage"><b>My Biz</b></a></div>
<div class="col-md-1"> <a class="nav-link" href="biz-subs-manage"><b>My Subs</b></a></div>

	<?php
		$biz_admin_user = $_SESSION['login'] ;
		$biz_user_role = $_SESSION['biz_role'] ; // If SU -> then see all biz users and all business -
		if ($biz_user_role == "SU") {
			echo '<div class="col-md-1">	<a class="nav-link" href="biz-users">All Users</a></div>';
			echo '<div class="col-md-1">	<a class="nav-link" href="biz-all">All Biz</a></div>' ;
			echo '<div class="col-md-1">	<a class="nav-link" href="biz-subs-manage-admin">All Subs</a></div>' ;
		}
	else {
		echo 	'<div class="col-md-3"> </div>' ;
	}
?>

	<div class="col-md-1">	<a class="nav-link" href="biz-logout">Log Out</a></div>
	<div class="col-md-2">	Welcome <?php echo $biz_admin_user; ?></div>
	
	

</div>
</div>
</nav>
