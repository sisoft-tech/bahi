--- 18/11/2024

CREATE TABLE biz_settings 
(
    id BIGINT AUTO_INCREMENT PRIMARY KEY,              -- Unique identifier for each setting
    biz_id BIGINT NOT NULL,                            -- Reference to a specific business or application
    setting_key VARCHAR(255) NOT NULL,                -- Key for the setting (e.g., 'theme_color', 'max_users')
    setting_value TEXT NOT NULL,                      -- Value for the setting
    data_type VARCHAR(50) NOT NULL,                   -- Data type of the value (e.g., 'integer', 'string', 'boolean')
    default_value TEXT,                               -- Default value for the setting
    is_editable BOOLEAN DEFAULT TRUE,                 -- Flag to indicate if the setting can be modified
    created_by BIGINT NOT NULL,                       -- Reference to the user who created the record
    updated_by BIGINT NOT NULL,                       -- Reference to the user who last updated the record
    created_dtm TIMESTAMP DEFAULT CURRENT_TIMESTAMP,  -- Timestamp of creation
    updated_dtm TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Timestamp of last update
--    CONSTRAINT fk_created_by FOREIGN KEY (created_by) REFERENCES Users(id), -- Foreign key to Users table
--    CONSTRAINT fk_updated_by FOREIGN KEY (updated_by) REFERENCES Users(id)  -- Foreign key to Users table
);

setting_key=array("EnableGST", "GSTIN", RegTypeGST, EnablePharma, DrugLicNo, EnableFood, FssiNum)


=> Add Drug License Number for Parties  for Pharma Business. 
	ALTER TABLE `account_ledger` ADD `custom_fld1` VARCHAR(64) NULL AFTER `contact_person_name`;
	=> comp-info.php 
	=> vendor-add.php
	=> vendor-update.php
	=> customer-add.php
	=> customer-update.php
	=> bill-share-view.php
	=>

	=> CREATE TABLE IF NOT EXISTS `biz_settings` (
  `biz_id` bigint(20) NOT NULL,
  `enable_pharma` varchar(1) NOT NULL DEFAULT 'N',
  `drug_lic_no` varchar(32) NOT NULL,
  `enable_batch` varchar(1) NOT NULL DEFAULT 'N',
  `created_by` bigint(20) NOT NULL,
  `updated_by` bigint(20) NOT NULL,
  `created_dtm` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_dtm` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`biz_id`)
)

	=> config-biz-settings.php



=> Use Price in Sales Invoice ( MRP, SALES PRICE )
	=> Added New Column in table :config_sales_invoice  Column Name : use_price
	=> config-sale-invoice.php 

=> Price Inclusive of GST/ GST Extra ??

==> Add Batch Information in Product Items  
	=> CREATE TABLE IF NOT EXISTS `product_item_batch_details` (
  `batch_id` int(11) NOT NULL AUTO_INCREMENT,
  `biz_id` int(2) NOT NULL,
  `item_id` int(5) NOT NULL,
  `batch_no` varchar(32) NOT NULL,
  `mfg_dt` date NOT NULL,
  `exp_dt` date NOT NULL,
  `qty` int(11) DEFAULT NULL,
  `created_dtm` datetime NOT NULL,
  `created_by` varchar(64) NOT NULL,
  PRIMARY KEY (`batch_id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;


==> Add Serial Number Information for Product Items 
	=> CREATE TABLE product_item_serial_numbers (
    serial_number VARCHAR(50) PRIMARY KEY,
    product_id INT NOT NULL,
    status ENUM('In Stock', 'Sold', 'Returned') DEFAULT 'In Stock',
    last_transaction_id VARCHAR(50)
	);



