<?php

/****************************************
Example of how to use this uploader class...
You can uncomment the following lines (minus the require) to use these as your defaults.
/******************************************/

session_start();
require_once("../../includes/xmlrpc.inc.php");
require_once("../../modules/pkgs/includes/xmlrpc.php");
require_once("../../modules/pkgs/includes/functions.php");


// list of valid extensions, ex. array("jpeg", "xml", "bmp")
$allowedExtensions = array();
// max file size in bytes
$sizeLimit = get_php_max_upload_size() * 1024 * 1024;

//require('valums-file-uploader/server/php.php');
$uploader = new qqFileUploader($allowedExtensions, $sizeLimit);

// Call handleUpload() with the name of the folder, relative to PHP's getcwd()

// Put uploaded file in PHP upload_tmp_dir / random_dir
$upload_tmp_dir = sys_get_temp_dir();
$random_dir = ($_GET['random_dir']) ? $_GET['random_dir'] : $_SESSION['random_dir'];
$p_api_id = $_GET['selectedPapi'];
mkdir($upload_tmp_dir . '/' . $random_dir);

$result = $uploader->handleUpload($upload_tmp_dir, $random_dir, $p_api_id);

// to pass data through iframe you will need to encode all html tags
echo htmlspecialchars(json_encode($result), ENT_NOQUOTES);




/**
 * Handle file uploads via XMLHttpRequest
 */
class qqUploadedFileXhr {
    /**
     * Save the file to the specified path
     * @return boolean TRUE on success
     */
    function save($path, $filename, $random_dir, $p_api_id) {    
        $input = fopen("php://input", "r");
        $temp = tmpfile();
        $realSize = stream_copy_to_stream($input, $temp);
        fclose($input);
        
        if ($realSize != $this->getSize()){            
            return false;
        }
        
        $target = fopen($path, "w");        
        fseek($temp, 0, SEEK_SET);
        stream_copy_to_stream($temp, $target);
        fclose($target);

        // Push file to package server
        $upload_tmp_dir = sys_get_temp_dir();

        $files = array();
        $file = $upload_tmp_dir . '/' . $random_dir . '/' . $filename;
        // Read and put content of $file to $filebinary
        $filebinary = fread(fopen($file, "r"), filesize($file));
        $files[] = array(
            "filename" => $filename,
            "filebinary" => base64_encode($filebinary),
        );

        $push_package_result = pushPackage($p_api_id, $random_dir, $files);
        // Delete package from PHP /tmp dir
        delete_directory($upload_tmp_dir . '/' . $random_dir);
        
        if (!isXMLRPCError() and $push_package_result) {
            return true;
        }
    }
    function getName() {
        return $_GET['qqfile'];
    }
    function getSize() {
        if (isset($_SERVER["CONTENT_LENGTH"])){
            return (int)$_SERVER["CONTENT_LENGTH"];            
        } else {
            throw new Exception('Getting content length is not supported.');
        }      
    }   
}

/**
 * Handle file uploads via regular form post (uses the $_FILES array)
 */
class qqUploadedFileForm {  
    /**
     * Save the file to the specified path
     * @return boolean TRUE on success
     */
    function save($path) {
        if(!move_uploaded_file($_FILES['qqfile']['tmp_name'], $path)){
            return false;
        }
        return true;
    }
    function getName() {
        return $_FILES['qqfile']['name'];
    }
    function getSize() {
        return $_FILES['qqfile']['size'];
    }
}

class qqFileUploader {
    private $allowedExtensions = array();
    private $sizeLimit = 10485760;
    private $file;
	private $uploadName;

    function __construct(array $allowedExtensions = array(), $sizeLimit = 10485760){        
        $allowedExtensions = array_map("strtolower", $allowedExtensions);
            
        $this->allowedExtensions = $allowedExtensions;        
        $this->sizeLimit = $sizeLimit;
        
        $this->checkServerSettings();       

        if (isset($_GET['qqfile'])) {
            $this->file = new qqUploadedFileXhr();
        } elseif (isset($_FILES['qqfile'])) {
            $this->file = new qqUploadedFileForm();
        } else {
            $this->file = false; 
        }
    }
    
	public function getUploadName(){
		if( isset( $this->uploadName ) )
			return $this->uploadName;
	}
	
	public function getName(){
		if ($this->file)
			return $this->file->getName();
	}
    
    private function checkServerSettings(){        
        $postSize = $this->toBytes(ini_get('post_max_size'));
        $uploadSize = $this->toBytes(ini_get('upload_max_filesize'));        
        
        if ($postSize < $this->sizeLimit || $uploadSize < $this->sizeLimit){
            $size = max(1, $this->sizeLimit / 1024 / 1024) . 'M';             
            $php_ini_path = get_cfg_var('cfg_file_path');
            die("{'error':'increase post_max_size and upload_max_filesize to $size in $php_ini_path'}");    
        }        
    }
    
    private function toBytes($str){
        $val = trim($str);
        $last = strtolower($str[strlen($str)-1]);
        switch($last) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;        
        }
        return $val;
    }
    
    /**
     * Returns array('success'=>true) or array('error'=>'error message')
     */
    function handleUpload($uploadDirectory, $random_dir, $p_api_id, $replaceOldFile = FALSE){
        $uploadDirectory .= '/' . $random_dir . '/';
        if (!is_writable($uploadDirectory)){
            return array('error' => "Server error. Upload directory isn't writable.");
        }
        
        if (!$this->file){
            return array('error' => 'No files were uploaded.');
        }
        
        $size = $this->file->getSize();
        
        if ($size == 0) {
            return array('error' => 'File is empty');
        }
        
        if ($size > $this->sizeLimit) {
            return array('error' => 'File is too large');
        }
        
        $pathinfo = pathinfo($this->file->getName());
        $filename = $pathinfo['filename'];
        //$filename = md5(uniqid());
        $ext = @$pathinfo['extension'];		// hide notices if extension is empty

        if($this->allowedExtensions && !in_array(strtolower($ext), $this->allowedExtensions)){
            $these = implode(', ', $this->allowedExtensions);
            return array('error' => 'File has an invalid extension, it should be one of '. $these . '.');
        }
        
        $ext = ($ext == '') ? $ext : '.' . $ext;

        if(!$replaceOldFile){
            /// don't overwrite previous files that were uploaded
            while (file_exists($uploadDirectory . $filename . $ext)) {
                $filename .= rand(10, 99);
            }
        }
        
	$this->uploadName = $filename . $ext;
		
        if ($this->file->save($uploadDirectory . $filename . $ext, $filename . $ext, $random_dir, $p_api_id)){
            return array(
                'success' => true,
                'random_dir' => $random_dir
            );
        } else {
            return array('error'=> 'Could not save uploaded file.' .
                'The upload was cancelled, or server error encountered');
        }
        
    }    
}
?>
