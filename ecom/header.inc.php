<?php 
include_once 'include/dbi.php';
include_once 'include/dbo.php' ;

$dbh = new dbo() ;

$uname = $_SESSION['biz_user_name'] ;

$biz_id = $_SESSION['biz_id'] ;	
include 'biz-info.php';

$biz_name = $comp_name  ;
$hashcode_biz_id = base64_encode($biz_id);

$username_head = $uname;

date_default_timezone_set('Asia/Kolkata');
$date_head = date('Y-m-d'); 

?>

<div class='col-md-12 old_header' style='background-repeat: repeat-x; left:0;right:0; position:absolute; top:0; width:100%;'>
<div class='row'>
<div class='col-xs-4' style= 'text-align:left;  color: #7B7D7D;'>
<h5 id='clock'> <script>
var date = new Date();

var d1 = date.toISOString().slice(0,10);
function checkTime(i) {
  if (i < 10) {
    i = '0' + i;
  }
  return i;
}

function startTime() {
  var today = new Date();
  var h = today.getHours();
  var m = today.getMinutes();
  var s = today.getSeconds();
  // add a zero in front of numbers<10
  m = checkTime(m);
  s = checkTime(s);
  document.getElementById('clock').innerHTML = d1+ ' '+ h + ':' + m + ':' + s;
  t = setTimeout(function() {
    startTime()
  }, 500);
}
startTime();
</script></h5>
 </div>
 
 <div class='col-xs-4' style= 'text-align:center;  color: #7B7D7D;'>
 <h4><?php echo $biz_name ;?></h4>
 </div>
<div class='col-xs-4' style= 'text-align:right;  color: #7B7D7D;'>
 <h4>Welcome <?php echo $username_head.'!'; ?></h4>
</div>
</div>
</div>
<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css'>
<style>
.dropdown-header{
  background-color:black;
  color:white;
}
.dropdown-submenu {
    position: relative;
  
}
.dropdown-toggle{
  border-radius:0;
}
.dropdown-toggle:hover{
  background-color:#ccf2ff;
  color:black;
}
#btn1:focus{
  background-color:white;
  color:black;
}

.dropdown-submenu .dropdown-menu {
    top: 0;
    left: 100%;
    margin-top: -1px;
}
.navigation{
  background-color:#003333;
  margin-top:37px;
  left:0;
  right:0;
  z-index: 1;
  
  
}
#btn_home{
  border-radius:0;
  background-color:#92d36e;
  color:#161616;
}

#btn_logout{
  border-radius:0;
  
  background-color:#dd8140;
  color:#fff;
}

#btn_home:hover{
  background-color:#84b26b;
  color:#fff;
  font-size:16px;
}

#btn_logout:hover{
  background-color:#f4c1ad;
  color:#000000;
  font-size:16px;
}

.list_open{
  display:none;
}
.fixed {
  position: fixed;
  top:-37px; 
  width: 100%; 
  left:0;
  right:0;
  }
  
.mob_view{
    display:none;
}

@media only screen and (max-width: 800px) { 

.navigation{
  top:-37px;
  width:100%;
  left:0;
  right:0;
  margin-bottom:-100px;
  
}
.fixed {
  position: fixed;
  width: 100%; 
  left:0;
  
}
.fixed .list_open .glyphicon-align-justify{
  color: #fff;
  font-size: 1.2em;
  float:right;  
}
.navigation .mob_view{
  display:block;
  color:white;
  font-size:16px;
  margin-left:-30px;
}


.navigation .list_open{
  display:block;
}

.navigation .list_open .glyphicon-align-justify{
  color: #fff;
  font-size: 1.2em;
  float:right;
  margin-top:-20px;
}
  .old_header{
    display:none;
  }
  .web_view{
    display:none;
  }
 #btn_home{
 display:none;
 } 
  #btn_logout{
  margin-left:0;
  }
  #btn1{
    width:200px;
  }
  .lead_btn{
    margin-left:15px;
  }
  .sales_resource_btn
  {
     margin-left:15px;
  }
  

}
</style>

<div class='col-md-12 col-sm-12 col-lg-12 col-xs-12 navigation '>
<div class='mob_view'>
<div class='col-sm-1'>
<a style='color: #fff;' href='ecom-index.php'><button class='btn btn-primary' type='button'><i class='fa fa-home'></i></button></a><?php echo $biz_name ;?>
</div>

</div>
<div class='row web_view' id='web_view'>

<?php

echo "

<div class='col-md-2'>
<a style='color: #fff;' href='ecom-index.php'><button class='btn' id='btn_home' type='button'><i class='fa fa-home'></i>&nbsp;<strong>Dashboard</strong></button></a>
</div>" ;


 
echo "
<div class='dropdown col-md-2'>
    <button class='btn btn-primary dropdown-toggle' id='btn1' type='button' data-toggle='dropdown'>Products
    <span class='fa fa-caret-down'></span></button>
    <ul class='dropdown-menu'>
      <li><a tabindex='-1' href='product-uom-manage.php'>Manage Unit of Measurement</a></li>	
   <li class='dropdown-header'>PRODUCT ITEM</li>
      <li><a tabindex='-1' href='product-item-add.php'>Add Product Item</a></li>
      <li><a tabindex='-1' href='product-item-manage.php'>Manage Product Item</a></li>
      <li><a tabindex='-1' href='product-item-avail.php'>Manage Item Availability/Quantity</a></li>
      <li><a tabindex='-1' href='product-item-image-manage.php'>Manage Product Item Images</a></li>
     <li class='dropdown-header'>PRODUCT GROUP</li>
      <li><a tabindex='-1' href='product-group-add.php'>Add Product Group</a></li>
      <li><a tabindex='-1' href='product-group-manage.php'>Manage Product Group</a></li>
    </ul>
</div>";



  echo "
<div class='dropdown col-md-2 col-sm-4' style='margin-left:0px;'>
    <button class='btn btn-primary dropdown-toggle lead_btn' id='btn1' type='button' data-toggle='dropdown'>Orders
    <span class='fa fa-caret-down'></span></button>
    <ul class='dropdown-menu'>
      <li><a tabindex='-1' href='myorders-new.php'>Pending</a></li>
	  <li><a tabindex='-1' href='myorders-processed.php'>Processed</a></li>
	  <li><a tabindex='-1' href='myorders-rejected.php'>Rejected</a></li>
    <!--<li class='dropdown-header'>REPORTS</li>
      <li><a tabindex='-1' href='training_lead_daily_report.php'>Daily Report Status</a></li>
      <li><a tabindex='-1' href='lead_report_for_duration.php'>Lead Report For Duration</a></li>
      <li><a tabindex='-1' href='lead-report2.php'>Lead Report2</a></li>-->
    </ul>
</div>";



/*
echo "
<div class='dropdown col-md-1' style='margin-left:0px; '>
    <button class='btn btn-primary dropdown-toggle sales_resource_btn' id='btn1' type='button' data-toggle='dropdown'>Promotions
    <span class='fa fa-caret-down'></span></button>
    <ul class='dropdown-menu'>
      <li><a tabindex='-1' href='mypromo-add.php'>Add Promotions</a></li>
      <li><a tabindex='-1' href='mypromo-manage.php'>Manage Promotions</a></li>
     
    </ul>
</div>";
*/
/*
  echo "
<div class='col-md-1'>
  <a href='../biz-local/my-shop.php?b=".$hashcode_biz_id."' target='_myShop'><button class='btn btn-primary dropdown-toggle' id='btn1'><strong> myShop</strong>&nbsp;</button></a>
  </div>
   
";

  echo "
<div class='col-md-1'>
  <a href='pos-index.php' target='_myPOS'><button class='btn btn-primary dropdown-toggle' id='btn1'><strong> myPOS</strong>&nbsp;</button></a>
  </div>
   
";
*/

/*
echo "
<div class='dropdown col-md-1'>
    <button class='btn btn-primary dropdown-toggle' id='btn1' type='button' data-toggle='dropdown'>Shop
    <span class='fa fa-caret-down'></span></button>
    <ul class='dropdown-menu'>

      <li><a tabindex='-1' href='my-shop.php?b=".$hashcode_biz_id."'>My Online Shop</a></li>
    </ul>
  </div>";
*/	
  echo "
<div class='col-md-2' >
  <a href='../biz-mybusiness-manage.php'><button class='btn' id='btn_logout'><strong>My Businesses</strong>&nbsp;<i class='fa fa-sign-out'></i></button></a>
  </div>

";


?>
</div>

</div>


<div >
  <a href='#' onclick="show_header()" class='list_open' id='list_open'><span class='glyphicon glyphicon-align-justify'></span></a>
  
</div>
<script>
$(document).ready(function(){
  $('.dropdown-submenu a.test').on('click', function(e){
    $(this).next('ul').toggle();
    e.stopPropagation();
    e.preventDefault();
  });
});

function show_header() {
    var x = document.getElementById("web_view");
    if (x.style.display === "none") {
        x.style.display = "block";
    } else {
        x.style.display = "none"; 
    }
}
</script>



<script>
$(window).scroll(function(){
  var sticky = $('.navigation'),
      scroll = $(window).scrollTop();

  if (scroll >= 60) sticky.addClass('fixed');
  else sticky.removeClass('fixed');
});
</script>