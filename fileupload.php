<?php
/**
 * Created by PhpStorm.
 * User: prathibha_w
 * Date: 7/25/2018
 * Time: 11:59 AM
 */

//$target_dir = "uploads/";
$target_dir = "../wp-content/plugins/epic-ipg/keystore/";

//$mer = new WC_epic_Payment_Gateway;
//$fff = $mer->merchant;

//get merchant ID
//$mer = $_POST['woocommerce_myepic_merchantId'];
$mer = $_POST['woocommerce_myepic_merchantId'];
//check is file button click or select a file to file upload button

$target_file = $target_dir . basename($_FILES["woocommerce_myepic_fileToUpload"]["name"]);
$target_file_name = pathinfo($_FILES['woocommerce_myepic_fileToUpload']['name'], PATHINFO_FILENAME);

$cerfile = $target_dir . basename($mer . ".cer");
$jksfile = $target_dir . basename($mer . ".jks");

$uploadOk = 1;
$fileOK = true;
$imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

function fileupload_error_notice($msg)
{
    ?>
    <div class="error notice">
        <p><b><?php _e($msg); ?></b></p>
    </div>
    <?php
}

function fileupload_success_notice($msg)
{
    ?>
    <div class="updated notice">
        <p><b><?php _e($msg); ?></b></p>
    </div>
    <?php
}

if (isset($_POST["submit"])) {
    $uploadOk = 1;
    if (true) {
        $uploadOk = 1;
    } else {
        $uploadOk = 0;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save'])) {

        if ($fileOK) {
            if ($imageFileType == '') {
                $uploadOk = 2;
            }
            else if (file_exists($target_file)) {
                $uploadmessage .= "File already exists.";
//                fileupload_error_notice("File already exists.");
                $uploadOk = 0;
            } //check file type
            else if ($imageFileType != "cer" && $imageFileType != "jks") {
                $uploadmessage .= "Only certificate files (.cer) & JKS files are allowed.";
//                fileupload_error_notice('Only certificate files (.cer) & JKS files are allowed.');
                $uploadOk = 0;
            }
            if($target_file_name != $mer && $uploadOk !=2 && $imageFileType == "cer" ){
                fileupload_error_notice("Uploaded file not mactched to merchant id");
                $uploadOk = 0;
            }

            if ($uploadOk == 0) {
                $uploadmessage .= "Your file was not uploaded.";
                fileupload_error_notice($uploadmessage);
            } else if ($uploadOk == 2) {
                $uploadOk = 22;
            } else if ($uploadOk == 1) {
                if (move_uploaded_file($_FILES["woocommerce_myepic_fileToUpload"]["tmp_name"], $target_file)) {
                    fileupload_success_notice("The file <strong>" . basename($_FILES["woocommerce_myepic_fileToUpload"]["name"]) . "</strong> has been uploaded.");
                } else {
                    fileupload_error_notice('Sorry, there was an error uploading your file.');
                }
            }
        }
        //check file already exists
        if (!file_exists($cerfile)) {
            fileupload_error_notice("Certificate file not exist.");
        } else {
            fileupload_success_notice("Certificate file exist.");
        }
    }
}


