<?php
include 'include/dbi.php';
include 'include/session.php';

$act_grp = $_POST['p_act_grp'] ;
$biz_id = $_POST['p_biz_id'] ;
$get=$_POST['p_cust_number'];

	$breakline=0;
	$sql = "SELECT account_id, account_name, phone_num FROM account_ledger WHERE biz_id='$biz_id' and ac_group_code='$act_grp' and phone_num LIKE '%$get%' order by account_name";
	$fetch=mysqli_query($conn,$sql) or die(mysqli_error($conn));
    $rowcount=mysqli_num_rows($fetch);
	echo "Number of displayed rows:$rowcount <br>" ;

	echo"<select class='form-control' id='name_option' onChange='set_party(this.value);'>";
	echo "<option>Search...</option>";
	while($record=mysqli_fetch_array($fetch))
	{
		$breakline++;
   
		echo "<option value=".$record['account_id'].":".$record['account_name'].":".">".$record['account_name'].":".$record['phone_num']."</option>"; 

		if($breakline==20)
			break;
   
	}
	echo"</select>";
?>