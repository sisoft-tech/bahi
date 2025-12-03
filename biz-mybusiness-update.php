<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/param-pos.php';

$debug=0;
//if (isset($_GET['dbg'])) {
//	$debug=$_GET['dbg'] ;
//}



 
 if (isset($_POST['update'])) {
	 $update_id = $_POST['update_id'];
    $category  = $_POST['category'];
    $cname     = $_POST['cname'];
    $aboutus   = $_POST['aboutus'];
    $street_add  = $_POST['caddress1'];
	$khand_name = $_POST['ckhand'] ;
    $city      = $_POST['city'];
	$district  = $_POST['district'];
    $state     = $_POST['state'];
	$country     = $_POST['country'];
    $pin       = $_POST['pin'];
    $url       = $_POST['weburl'];
    $email     = $_POST['cemail'];
    $phone1     = $_POST['cphone1'];
    $phone2     = $_POST['cphone2'];
    
	$currency = $_POST['currency'] ;
	$biz_tax_reg_status = $_POST['biz_tax_reg_status'] ;
	$biz_gstin = $_POST['biz_gstin'] ;

	
	$update_sql = "UPDATE biz_establishment SET biz_name='$cname', biz_details='$aboutus', biz_phone1='$phone1', biz_phone2='$phone2', biz_email='$email', biz_website='$url', biz_street='$street_add', bcat_id='$category', biz_area='$khand_name',  biz_city='$city', biz_district='$district', biz_state='$state', biz_country='$country', biz_pin='$pin', biz_currency='$currency', biz_tax_reg_status='$biz_tax_reg_status', biz_gstin='$biz_gstin'	where biz_id='$update_id'"; 
  
	if ($debug) echo $update_sql ;
	
   $result = mysqli_query($conn, $update_sql);
   if ($result){
		$update_estb_cat="UPDATE biz_estab_cat SET bcat_id='$category' WHERE biz_id='$update_id'";
		mysqli_query($conn, $update_estb_cat) ;
		//header("location:biz-mybusiness-manage.php");
    }
	else{
		echo "Error: " . "<br>" .  mysqli_error($conn);
	}
}

if(isset($_POST['update_id'])){	
 $update_id = $_POST['update_id'];
 $base_qry = "SELECT * from biz_establishment where biz_id='$update_id'";
 if ($debug) echo $base_qry;
 $result=mysqli_query($conn, $base_qry);
 $row = mysqli_fetch_array($result);
 $bcat_id=$row['bcat_id'];
 } 	

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>Update my business</title>
<link rel="shortcut icon" type="image/icon" href="image/icon-main.png"/>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="description" content="Business Listing for Local Business - Add your business , free business listing  " />
<meta name="keywords" content="Free Business Listing" />
<!-- SCRIPT-->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">


<style type="text/css">
      
	.row{
	text-decoration: none;
	font-size: 16px;	
	font-weight:bold;
	margin-top:10px;
} 
    </style>
	
<script type="text/javascript">
$(document).ready(function(){
    $('select').formSelect();
  });


</script>	

</head>
<body>

    <?php 

	   include("biz-header.php");

	   ?>

<div class="container">
<div class="row">	
	<div class="col-sm-2" style="margin-top:30px;">
		<button type ="submit" class="btn" onClick="window.location.assign('biz-profile.php')">Back</button>
   </div>
   <div class="col-sm-10">
		<h3 style="text-align: center;"> Business Update </h3>
	</div>
</div>
<br>


	
    <form method="POST" name="testform" enctype="multipart/form-data" onsubmit ="return ckeckData();">
		<input type="hidden" name="update_id" value="<?php echo $update_id; ?>" />
    	<div class="row">
			<div class="col-lg-2">
				<label>Choose Category</label>
			</div>
			<div class="col-lg-4">
			
				<select id="category" name="category">
                                            <option value="" disabled selected>Choose Category</option>
                                            <?php $cat_query = mysqli_query($conn, "SELECT * FROM biz_category ORDER BY bcat_name ASC");
                                            while ($categories = mysqli_fetch_array($cat_query,MYSQLI_ASSOC)) {
                                                ?>
                                            <option value="<?=$categories['bcat_id']?>" 
												<?php if($categories['bcat_id']==$bcat_id) echo "selected";?> >
												<?=$categories['bcat_name']?>
											</option>
                                             
                                            <?php }?>
                                               
                </select>
			</div>

			<div class="col-lg-2">
				<label for="cname">Business Name(*)</label>
			</div>
			<div class="input-field col-lg-4">
				<input required name="cname" type="text" value="<?php echo $row['biz_name']; ?>" id="cname"/>
            </div>			

		</div>
			
		<div class="row">
			<div class="col-lg-2">
				<label for="aboutus">About Business(*)</label>
			</div>
			<div class="col-lg-10">
				<textarea required name="aboutus" value="" id="aboutus" style="width: 765px; height: 50px;" ><?php echo $row['biz_details']; ?></textarea>
			</div>
		</div>

		<div class="row">
			<div class="col-sm-2">
				<label for="web_url">Business WebSite<br> (if any)</label>
			</div>
			<div class="input-field col-sm-4">
				<input type="text" name="weburl" value="<?php echo $row['biz_website']; ?>" id="weburl" />	
			</div>
			<div class="col-sm-2">
				<label for="email">Email(*)</label>
			</div>
			<div class="input-field col-sm-4">
				<input required  name="cemail" type="text" value="<?php echo $row['biz_email']; ?>" id="cemail" />
				
			</div>
		</div>

			<div class="row">
			<div class="col-sm-2">
				<label for="phone">Phone(*)</label>
			</div>
			 <div class="input-field col-sm-4">
				<input required name="cphone1" type="text" maxlength="10" value="<?php echo $row['biz_phone1']; ?>" id="cphone1" class="validate"/>
			</div>
			<div class="col-sm-2">
				<label for="alt_phone">Alt Phone</label>
			</div>
				<div class="input-field col-sm-4">
				<input name="cphone2" type="text" maxlength="10" value="<?php echo $row['biz_phone2']; ?>" id="cphone2"/> 
				</div>
			</div>


			<div class="row">
			<div class="col-sm-2">
					<label for="street_address">Street Address</label>
			</div>
			<div class="input-field col-sm-4">
				<input name="caddress1" type="text" value="<?php echo $row['biz_street']; ?>" id="caddress1"/>
			</div>
			<div class="col-sm-2">
				<label for="ckhand">Area</label>
			</div> 
			<div class="input-field col-sm-4">
				<input name="ckhand" type="text" value="<?php echo $row['biz_area']; ?>" id="ckhand"/>
				</div>
			</div>
			
			<div class="row">
			<div class="col-sm-2">
				<label for="pin">Pin Code(*)</label>
			</div>		
			<div class="input-field col-sm-4">
				<input required name="pin" type="text"  id="pin" value="<?php echo $row['biz_pin']; ?>"/>
			 </div>
			<div class="col-sm-2">
				<label for="city">City</label>
			</div>		
			
			<div class="input-field col-sm-4">
				<input required  name="city" type="text" id="city" value="<?php echo $row['biz_city']; ?>"/> 
			</div>
			

			</div>
			
			<div class="row">
				<div class="col-sm-2">
					<label for="district">District</label>
				</div>		
			
				<div class="input-field col-sm-4">
					<input name="district" type="text"  id="district" value="<?php echo $row['biz_district']; ?>" />
				</div>

				<div class="col-sm-2">
					<label for="state">State(*)</label>
				</div>		
				<div class="input-field col-sm-4">
		    	<select class="form-control" name="state" required=required>
				<option value="">Choose State</option>
				<?php 
					$state_value = $row['biz_state'];
					for ($i=0;$i<count($list_india_state); $i++)
					{
						$selected="" ; 
						if ($state_value==$list_india_state[$i]) $selected="selected" ;
						echo "<option value='$list_india_state[$i]' $selected>$list_india_state[$i]</option>" ;
					}
				?>
				</select>
            	</div>			
			</div>
			

			<div class="row">
				<div class="col-sm-2">
					<label for="country">Country</label>
				</div>		
				<div class="input-field col-sm-4">
					<input name="country" type="text" value="<?php echo $row['biz_country']; ?>" id="country"/> 
            	</div>			
				
				<div class="col-sm-2">
					<label for="currency">Currency</label>
				</div>		

				<div class="input-field col-sm-4">
					<input name="currency" type="text" value="<?php echo $row['biz_currency']; ?>" id="currency"/>
				</div>
			</div>

			<div class="row">
				<div class="col-sm-2">
					<label for="biz_tax_reg_status">Business Tax Registration Status</label> 
				</div>		

				<div class="input-field col-sm-4">
					<select name="biz_tax_reg_status" id="biz_tax_reg_status">
						<option value="" disabled selected>Choose Tax Registration Status</option>		
						<option value="U" <?php if ($row['biz_tax_reg_status']=="U") echo " selected";?>>Un-Registered</option>
						<option value="R" <?php if ($row['biz_tax_reg_status']=="R") echo " selected";?>>Registered</option>
					</select>
            	</div>			
				
				<div class="col-sm-2">
					<label for="biz_gstin">GSTIN</label>
				</div>		

				<div class="input-field col-sm-4">
					<input name="biz_gstin" type="text" value="<?php echo $row['biz_gstin']; ?>" id="biz_gstin"/>
				</div>
				

			</div>
		
		
		  <div class="row">
		  	<div class="col-sm-4"></div>
                <div class="col-sm-2">
                	<button class="btn" type="submit" name="update">
    				Submit
  					</button> 
                </div>
               <div class="col-sm-2">
               	<button class="btn" type="reset" name="Reset" onClick="location.href = 'biz-profile.php';"> 	Reset </button> 
               </div>
           </div>
      </form>
		<div class="row" style=margin-top:20px;">
		  	<div class="col-sm-12">
			(*) Item marked are mandatory
			</div>
        </div>

<!--
<div class="container-fluid">
 <div class="row">	
<div class="col s12 m3 l2" style="margin-top:30px;">
<button type ="submit" class="btn  grey  waves-effect waves-light" 
					onClick="window.location.assign('biz-mybusiness-manage.php')">
   		Back<i class="material-icons left">chevron_left</i>
   	</button>
   </div>
   <div class="col s12 m9 l8">
<h3 class="center-align blue-text light">Business Update</h3>
</div>
</div>
		<div class="row">
	<div class="col s12 m12 l12">
      <form method="POST" name="testform">
		
      		<div class="row">
			<div class="col s12 m5 l5">
			<label>Choose Category</label>
			<i class="material-icons left">dehaze</i>
				<select class = "browser-default" id="category" name="category">
					                                            <?php $cat_query = mysqli_query($conn, "SELECT * FROM biz_category");
					                                            while ($categories = mysqli_fetch_array($cat_query,MYSQLI_ASSOC)) {
					                                            	if($categories['bcat_id']==$bcat_id)
					                                            	{
					                                                ?>
					                                            <option value="<?=$categories['bcat_id']?>" selected><?=$categories['bcat_name']?></option>
					                                             
					                                                <?php }
					                                                	else{
					                                                ?>
					                                                <option value="<?=$categories['bcat_id']?>"><?=$categories['bcat_name']?></option>
					                                                  <?php }
					                                              }
					                                              ?>
                                               
                </select>
			</div>
			
			<div class="input-field col s12 m5 l5">
				<i class="material-icons prefix">phone</i>
				<input name="cphone1" type="text" value="<?php echo $row['biz_phone1']; ?>" id="cphone1" />
				<label for="phone">Phone</label>
			</div>
			<input type="hidden" name="update_id" value="<?php echo $update_id; ?>" />

		</div>
			
		<div class="row">
			<div class="input-field col s12 m5 l5">
				<i class="material-icons prefix">business</i>
				<input name="cname" type="text" id="cname" value="<?php echo $row['biz_name']; ?>"/>
				<label for="company_name">Company Name</label>
            </div>

			<div class="input-field col s12 m5 l5">
				<i class="material-icons prefix">group</i>
				<textarea name="aboutus" value="" id="aboutus" class="materialize-textarea"><?php echo $row['biz_details']; ?></textarea>
				<label for="about_us">About Us</label>
			</div>
		</div>

		<div class="row">

				<div class="input-field col s12 m5 l5">
				<i class="material-icons prefix">language</i>
				<input type="text" name="weburl" value="<?php echo $row['biz_website']; ?>" id="weburl"/>
				<label for="web_url">Web URL</label>
				</div>
			<div class="input-field col s12 m5 l5">
				<i class="material-icons prefix">mail_outline</i>
				<input name="cemail" type="text" id="cemail" value="<?php echo $row['biz_email']; ?>" />
				<label for="email">Email</label>
			</div>
		</div>

			<div class="row">
				<div class="input-field col s12 m5 l5">
					<i class="material-icons prefix">phone</i>
				<input name="cphone2" type="text"  id="cphone2" value="<?php echo $row['biz_phone2']; ?>"/> 
				<label for="alt_phone">Alt Phone</label>
				</div>
			
			<div class="input-field col s12 m5 l5">
				<i class="material-icons prefix">place</i>
				<input name="caddress1" type="text" id="caddress1" value="<?php echo $row['biz_street']; ?>"/>
					<label for="street_address">Street Address</label>
				</div>
			</div>

			<div class="row">
		  
				<div class="input-field col s12 m5 l5">
					<i class="material-icons prefix">place</i>
				<input name="ckhand" type="text" id="caddress1" value="<?php echo $row['biz_area']; ?>" />
				<label for="ckhand">Sector/Khand</label>
				</div>
		
			<div class="input-field col s12 m5 l5">
				<i class="material-icons prefix">place</i>
				<input name="pin" type="text" id="pin" value="<?php echo $row['biz_pin']; ?>"/>
				<label for="pin">Pin Code</label>
			 </div>

			
			</div>
			
			<div class="row">
				<div class="input-field col s12 m5 l5">
					<i class="material-icons prefix">place</i>
				<input name="city" type="text" id="city" value="<?php echo $row['biz_city']; ?>"/> 
				<label for="city">City</label>
			</div>
			
			<div class="input-field col s12 m5 l5">
				<i class="material-icons prefix">place</i>
			<input name="district" type="text" id="district" value="<?php echo $row['biz_district']; ?>"/>
			<label for="district">District</label>
			 </div>
			</div>
			
			<div class="row">
				<div class="input-field col s12 m5 l5">
					<i class="material-icons prefix">place</i>
				<input name="state" type="text" id="state" value="<?php echo $row['biz_state']; ?>"/> 
				<label for="state">State</label>
            	</div>
			
			
		</div>


			<div class="row">
				<div class="input-field col s12 m5 l5">
					<i class="material-icons prefix">place</i>
					<input name="country" type="text" value="<?php echo $row['biz_country']; ?>" id="country"/> 
					<label for="country">Country</label>
            	</div>			



				<div class="input-field col s12 m5 l5">
					<i class="material-icons prefix">money</i>
					<input name="currency" type="text" value="<?php echo $row['biz_currency']; ?>" id="currency"/>
					<label for="currency">Currency</label>
				</div>

				
			</div>

			<div class="row">
				<div class="input-field col s12 m5 l5">
					<i class="material-icons prefix">money</i>
					<select name="biz_tax_reg_status" id="biz_tax_reg_status">
						<option value="" disabled selected>Choose Tax Registration Status</option>		
						<option value="U" <?php if ($row['biz_tax_reg_status']=="U") echo " selected";?>>Un-Registered</option>
						<option value="R" <?php if ($row['biz_tax_reg_status']=="R") echo " selected";?>>Registered</option>
					</select>
					<label for="biz_tax_reg_status">Business Tax Registration Status</label> 
            	</div>			
				<div class="input-field col s12 m5 l5">
					<i class="material-icons prefix">money</i>
					<input name="biz_gstin" type="text" value="<?php echo $row['biz_gstin']; ?>" id="biz_gstin"/>
					<label for="biz_gstin">GSTIN</label>
				</div>
				

			</div>
			
			
		  <div class="row center-align">
		  	<div class="col s12 m10 l3"></div>
                <div class="input-field col s5 m2 l2">
                	<button class="btn waves-effect waves-light" type="submit" name="update">
    				Update<i class="material-icons left">near_me</i>
  					</button> 
                </div>
               <div class="input-field col s5 m2 l2">
               	<button class="btn red light waves-effect waves-light" type="reset" name="Reset" onClick="location.href = 'biz-mybusiness-manage.php';">
    				Cancel<i class="material-icons left">autorenew</i>
  				</button> 
               </div>
           </div>
		
        
      </form>
   </div>
</div>
</div>
-->
<div><?php //include "biz-footer.php"; ?></div>
</body>
</html>
