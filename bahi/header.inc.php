<div class='col-md-12 old_header' style='background:#ed7c65; left:0;right:0; width:100%;'>
<div class='row'>
<div class='col-xs-3' style= 'text-align:left;  color: #ffffff;'>
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

<?php
include 'include/dbi.php';
include_once 'include/dbo.php' ;
$dbh = new dbo() ;

$username_head = $_SESSION['pos_login'];
$pos_role = $_SESSION['pos_role'] ;

$biz_id = $_SESSION['biz_id'] ;
include 'company-info.php' ;

if($pos_role != 'owner'){
	$staff_sql="SELECT * FROM tbl_staff where username='$username_head'";
	$role_head=mysqli_query($conn, $staff_sql);
	$staff_row = mysqli_fetch_array($role_head);
	$staff_id = $staff_row['id'] ;
}

 ?>

 <div class='col-xs-6' style= 'text-align:center;  color: #ffffff;'>
 <h4>Euphoria Bahi - <?php echo $comp_name.":".$biz_id ; ?></h4>
 </div>

<div class='col-xs-3' style= 'text-align:right;  color: #ffffff;'>

 <?php echo "Welcome $username_head";?> <a href='logout.php'><button class='btn btn-info' id='btn_logout'><strong>
 <?php if ($pos_role == "owner") echo "My Business" ; else echo "Log Out" ;?></strong>&nbsp;<i class='fa fa-sign-out'></i></button></a>
</div>
</div>

</div>
<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css'>


<style>

/* ================= Header / Navigation Styles ================= */

.navigation {
  background-color: #337ab7;
  margin-top: 0;
  left: 0;
  right: 0;
  z-index: 9998;
  position: sticky;
  top: 0;
}

/* Dropdown section headings */
.navigation .dropdown-header {
  background-color: #000;
  color: #fff;
}

/* Top menu buttons */
.navigation .dropdown-toggle {
  border-radius: 0;
}

.navigation .dropdown-toggle:hover,
.navigation .dropdown-toggle:focus {
  background-color: #ccf2ff;
  color: #000;
}

/* Dropdown menu should appear above page content */
.navigation .dropdown-menu {
  z-index: 10000;
}

/* Optional submenu support */
.navigation .dropdown-submenu {
  position: relative;
}

.navigation .dropdown-submenu > .dropdown-menu {
  top: 0;
  left: 100%;
  margin-top: -1px;
}

/* Mobile menu icon hidden on desktop */
.list_open {
  display: none;
}

/* Mobile header hidden on desktop */
.mob_view {
  display: none;
}

/* Logout/My Business button */
#btn_logout {
  border-radius: 0;
  color: #fff;
}

/* ================= Mobile View ================= */

@media only screen and (max-width: 800px) {

  .navigation {
    top: 0;
    width: 100%;
    left: 0;
    right: 0;
    margin-bottom: 0;
  }

  .navigation .mob_view {
    display: block;
    color: #fff;
    font-size: 16px;
    margin-left: 0;
    padding: 5px 0;
  }

  .navigation .list_open {
    display: block;
  }

  .navigation .list_open .glyphicon-align-justify {
    color: #fff;
    font-size: 1.2em;
    float: right;
    margin-top: -20px;
  }

  .old_header {
    display: none;
  }

  .web_view {
    display: none;
  }
}

</style>

<div class='col-md-12 col-sm-12 col-lg-12 col-xs-12 navigation '>
<div class='mob_view'>
<div class='col-sm-1'>
<a style='color: #fff;' href='logout.php'><button class='btn btn-primary' type='button'><i class='fa fa-home'></i></button></a>
Euphoria Bahi - <?php echo $comp_name.":".$biz_id ; ?>
</div>
</div>


<div class='row web_view' id='web_view'>


<div class='col-md-1'>
<a style='color: #000;' href='pos-index'><button class='btn' id='btn_home' type='button'><strong>Dashboard</strong></button></a>
</div>
<?php
if (!isset($app_id)){
	$app_id = 'B' ;
}

if(($pos_role == 'admin')||($pos_role == 'owner')){
		$sql = "SELECT id, label, link, parent FROM menus where app_id='$app_id' and parent = 0 order by sort";
  }
  else
  {
	$sql = "SELECT id, label, link, parent FROM menus where app_id='$app_id' and parent = 0 AND id IN (SELECT menu_id from menu_user_permissions where user_id='".$staff_id."') order by sort";
  }

  //echo $sql;
  $result1 = mysqli_query($conn, $sql) or die("database error:". mysqli_error($conn));
  // Create an array to conatin a list of items and parents
  $menus = array(
    'items' => array(),
    'parents' => array()
  );
  $k = 0;
  // Builds the array lists with data from the SQL result
  while ($items = mysqli_fetch_assoc($result1)) {
	$menu_label =$items['label'];
	$menu_link =$items['link'];
     ?>
	
	<div class='dropdown col-md-1 col-sm-1' style='margin-left:1px;display:inline-block; text-align:center; '>
    <button class='btn btn-primary dropdown-toggle' type='button' data-toggle='dropdown' style='align-justify:center'><?php echo $menu_label ; ?> <span class='fa fa-caret-down'></span></button>
	
    <?php

	if(($pos_role == 'admin')||($pos_role == 'owner')){
		$submenu_qry = "SELECT id, label, link, parent FROM menus where parent =".$items['id']. " order by sort";
	  }
	  else{
		$submenu_qry = "SELECT id, label, link, parent FROM menus where parent =".$items['id']. " AND id IN (SELECT menu_id from menu_user_permissions where user_id='".$staff_id."') order by sort";
	  }
   // echo $submenu_qry;
    $submenu_result = mysqli_query($conn, $submenu_qry);
    $count = mysqli_num_rows($submenu_result);
    if($count>0){
        echo  "<ul class='dropdown-menu'>" ;
	}
    while($sub_items = mysqli_fetch_assoc($submenu_result)){     
      $submenu_label= $sub_items['label'];
      $submenu_link = $sub_items['link'];
	  $submenu_id 	= $sub_items['id'] ;
	  if ($submenu_link == 'TITLE') {
			echo "<li class='dropdown-header'>".$submenu_label."</li>" ;
	  }
	  else {
    ?>
    
      <li><a tabindex='-1' href='<?php echo $submenu_link; ?>'>
	  <?php echo $submenu_label ;?> </a></li>

    <?php
	  }
  }

    if($count>0){
    ?>
    </ul>
    <?php
    $k++;
  }
  ?>
  </div>

<?php




    // Create current menus item id into array
    $menus['items'][$items['id']] = $items;
    // Creates list of all items with children
    $menus['parents'][$items['parent']][] = $items['id'];
  }
  // Print all tree view menus 
  //echo createTreeView(0, $menus);
  ?>    


</div>

<div>
  <a href='#' onclick="show_header()" class='list_open' id='list_open'><span class='glyphicon glyphicon-align-justify'></span></a>
</div>
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

