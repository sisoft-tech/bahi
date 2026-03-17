 <?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/session.php';
// include 'include/param.php';
include 'include/dbo.php' ;

if(isset($_POST['OWNER_POS'])){
//echo "From Business Management Home:<br>" ; 	
	$biz_id = $_POST['biz_id'] ;
	$uname = $_POST['user_email'] ;
	$_SESSION['biz_user_name'] = $uname ;
	$_SESSION['pos_login'] = $uname ;
	$_SESSION['biz_id'] = $biz_id ;
	$_SESSION['pos_login_id'] = 0 ;
	$_SESSION['pos_role'] = 'owner';    // from Owner's Dashboard -- Need to consider two roles (owner/admin)
}

//******* HELPERS ******************/
function getCurrentDirectoryUrl() {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    );

    $protocol = $isHttps ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? '';

    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $dirPath = rtrim(str_replace('\\', '/', dirname($requestPath)), '/');

    if ($dirPath === '') {
        $dirPath = '/';
    }

    return $protocol . $host . $dirPath . '/';
}
/*********************************************/

$dbh = new dbo() ;

if (isset($_POST['biz_id'])){
	$biz_id = $_POST['biz_id'] ;
	$_SESSION['biz_id'] = $biz_id;
}
else if (isset($_SESSION['biz_id']))
{
	$biz_id = $_SESSION['biz_id'] ;
}
else
{
	header("location:../biz-mybusiness-manage.php") ;
	exit() ;
}


if (isset($_POST['user_email'])){
	$uname = $_POST['user_email'] ;
	$_SESSION['biz_user_name'] = $uname ;
	$_SESSION['pos_login'] = $uname ;

}
else if (isset($_SESSION['biz_user_name']))
{
	$uname = $_SESSION['biz_user_name'] ;
	$_SESSION['pos_login'] = $uname ;

}
else
{
	header("location:../biz-mybusiness-manage.php") ;
	exit() ;
}



$hashcode_biz_id = base64_encode($biz_id);



?>
<html>
<head>
<meta http-equiv='Content-Type' content='text/html; charset=utf-8' />
<meta name='viewport' content='width=device-width, initial-scale=1'>
<link rel='icon' type='image/png' href='images/icon.png' />
<title>Ecommerce - Admin Panel</title>
 <link rel='stylesheet' href='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css'>
 <script src='https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js'></script>
 <script src='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js'></script>
<!-- Bootstrap Date-Picker Plugin -->
<script type='text/javascript' src='https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.4.1/js/bootstrap-datepicker.min.js'></script>
<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.4.1/css/bootstrap-datepicker3.css'/>

<style>

.flip-card {
  background-color: transparent;
  width: 300px;
  height: 200px;
  perspective: 1000px;
}

.flip-card-inner {
  position: relative;
  width: 100%;
  height: 100%;
  text-align: center;
    margin-top:20px;
  transition: transform 0.6s;
  transform-style: preserve-3d;
  box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);
}



.flip-card-front, .flip-card-back {
  position: absolute;
  width: 100%;
  height: 100%;
  backface-visibility: hidden;
}

.flip-card-front {
  background-color: #B39DDB;
  color: black;
    text-align: center;
    padding-top: 40px;
}

.flip-card-back {
  background-color: #2980b9;
  color: white;
 
}
</style>
    
<script>
$(document).ready(function(){
	$('#mob').keyup(function(){
		var getval=document.getElementById('mob').value;
		
		$.post('search_by_mob.php',
		{
			passval:getval
		},
		function(data, status){
					document.getElementById('place').innerHTML=data;
	});
	
});
});

</script>

<script>
function fetch(val)
{
 $.ajax({
 type: 'post',
 dataType: 'text json',
 url: 'reg-json-mob-admin.php',
 data: {
  mob:val
 },
 success: function (response) {
  document.getElementById('mob').value=response[0].phone_no;
 $('#place').css('display','none');
  }
 });
}
</script>


<style>

.alert{
	float:right;
	width:650px; 
	margin-top:100px;
	}
	
	
</style>
</head>

<body style="background-color:#ccf2ff;">
<div class ='container-fluid' >   	<!-- body -->
	<div>
		<?php include 'header.inc.php'; ?>
	</div>

<div class="container"> 
<div style="margin-top:60px;">
<?php 
$shop_dir = getCurrentDirectoryUrl()."/biz-local/" ;
$shop_url = $shop_dir.'my-shop.php?b="'.$hashcode_biz_id.'"';
echo "<br><b>URL of Online Shop : <a href=".$shop_url." target=_myShop>". $shop_url."</a></b>" ;
?>
</div>

<div class='row' style='margin-bottom:50px; margin-top:80px;'>
 
 <div class="col-md-4">	
<div class="flip-card">
   <a href="myorders-new.php"><div class="flip-card-inner">
    <div class="flip-card-front">
        <h2>Online Sales / New Orders</h2> 
        <p><?php 
		$order_qry = "select order_status from order_header where biz_id ='$biz_id' and order_status = 'N'";
		$order_qry_result = mysqli_query($conn, $order_qry) ;
		$sal_num = mysqli_num_rows($order_qry_result);
		echo $sal_num;
		// echo $order_qry ;
		?>
		</p>
    </div>
    
  </div></a>
</div>
</div>


<div class="col-md-4">	
<div class="flip-card">
   <a href="biz-local/my-shop.php?b='<?php echo $hashcode_biz_id;?>'" target='_myShop'><div class="flip-card-inner">
    <div class="flip-card-front">
        <h2>Online Shop</h2> 
        <p>View Online Shop
		</p>
    </div>
    
  </div></a>
</div>
</div>

  
</div>

   		 


</div>  <!-- Container -->
  </div>
 
		<?php //include('footer.inc.php'); ?>
	 
</body>
</html>