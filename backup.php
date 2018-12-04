<?php
/**
 *	.history格式:
		path:base64(文件路径) md5:md5(文件) index:0
 *
 * @作者  jiyu-tec.com
 * @版本  1.0
 */
namespace jiyutec;
require_once 'aliyun-oss-php-sdk-2.3.0.phar';

use OSS\OssClient;
use OSS\Core\OssException;

class JYBACK{
	const FILEHISTORYFILEPATH = '.history';
	const FILEPUSHPATH = '.push';
	const BACKUPDIR = '../';
	const IGNORE = [
		'.',
		'..',
		self::FILEPUSHPATH,
		self::FILEHISTORYFILEPATH,
		'$RECYCLE.BIN',
		'System Volume Information',
		'jybaksys'
	];
	private $fileHistory = [];
	private $dateStamp;

	private static $alioss_accessKeyId = "LTAI***";
	private static $alioss_accessKeySecret = "wN***";
	private static $alioss_endpoint = "http://oss-cn-beijing.aliyuncs.com";
		// 存储空间名称
	private static $alioss_bucket = "b***";


	
	public function main(){
		$this->_init();

		$this->_findDiffFile(self::BACKUPDIR);

		$this->_upload();

		$this->_updateHistory();

		$this->_upload_history();
	}

	/**
	 * Short description.
	 *
	 * return 
	 */
	private function _init(){
		
		if(!file_exists(self::FILEHISTORYFILEPATH)){
			touch(self::FILEHISTORYFILEPATH);
		}

		if(!is_writable(self::FILEHISTORYFILEPATH)){
			echo "The ".self::FILEHISTORYFILEPATH." is not writable!!";
			exit;
		}
		
		if($history = $this->_download_history()){
			file_put_contents(self::FILEHISTORYFILEPATH,$history);
		}

		
		$fileHistoryTmp = file(self::FILEHISTORYFILEPATH);
		foreach ($fileHistoryTmp as $v){
			$v = trim($v);
			if(!$v){
				continue;
			}
			$_tmp = $this->_unserialize($v);
			$filePath = $_tmp['path'];
			$this->fileHistory[$filePath] = $_tmp;
		}
		unset($fileHistoryTmp);

		file_put_contents(self::FILEPUSHPATH,"");

		$this->dateStamp = date("Ymd");
	}
	
	private function _findDiffFile($filePath)
	{

		$rsd = opendir($filePath);
		while ($fileName = readdir($rsd))
		{
			if (in_array($fileName,self::IGNORE))
				continue;

			if (is_file($filePath.$fileName))
			{
				$_file_md5 = md5_file($filePath.$fileName);
				$_file_name_base64 = base64_encode($filePath.$fileName);

				if(isset($this->fileHistory[$_file_name_base64])){
					//对比已有库中的文件md5是否一致
					if($this->fileHistory[$_file_name_base64]['md5'] != $_file_md5){
						//写入上传队列
						$this->_push($filePath.$fileName,$_file_md5,$this->fileHistory[$_file_name_base64]['index']+1);
						echo $filePath.$fileName;echo "\t";
						echo "e\n";
					}
				}else{
					//写入上传队列
					$this->_push($filePath.$fileName,$_file_md5);
					echo $filePath.$fileName;echo "\t";
					echo "+\n";
				}
			}else 
			{
				$this->_findDiffFile($filePath.$fileName.'/');
			}
		}
		echo "\n";
	} // end func
	/**
	 * 写入待传列表
	 *
	 * return 
	 */
	private function _push($realPath,$md5,$index=0){
		
		$data = $this->_serialize(['real_path'=>$realPath,'md5'=>$md5,'index'=>$index]);
		
		file_put_contents(self::FILEPUSHPATH,$data,FILE_APPEND);
	}

	/**
	 * Short description.
	 *
	 * return 
	 */
	private function _serialize($data){
		$_data = [
			'path'	=> base64_encode($data['real_path']),
			'real_path'	=> $data['real_path'],
			'md5'	=> $data['md5'],
			'date'	=> $this->dateStamp,
			'index'	=> $data['index']
		];
		$_data = json_encode($_data,JSON_UNESCAPED_UNICODE);
		$_data .= "\n";
		return $_data;
	}
	/**
	 * Short description.
	 *
	 * return 
	 */
	private function _unserialize($str){
		$data = json_decode($str,true);
		return $data;
	}

	/**
	 * Short description.
	 *
	 * return 
	 */
	private function _upload(){
		//const FILEHISTORYFILEPATH = './.history';
		//const FILEPUSHPATH = './.push';
		$uploadList = file(self::FILEPUSHPATH);

		
		//$this->fileHistory;
		foreach ($uploadList as $v){
			$_data = $this->_unserialize($v);

			$rs = $this->_uploadFile($_data);
			if($rs){
				$this->fileHistory[$_data['path']] = $v;
			}
		}
	}

	/**
	 * Short description.
	 *
	 * return 
	 */
	private function _updateHistory(){

		foreach ($this->fileHistory as &$v){
			if(is_array($v)){
				$v = $this->_serialize($v);
			}
		}unset($v);
		

		file_put_contents(self::FILEHISTORYFILEPATH,implode("",$this->fileHistory));

	}
	
	/**
	 * Short description.
	 *
	 * return 
	 */
	private function _uploadFile($data){
		$object = substr($data['real_path'],strlen(self::BACKUPDIR));

		$object .= '.'.$data['date'].'.'.$data['index'];

		$filePath = __DIR__.'/'.$data['real_path'];
		
		echo "\t";
		echo $filePath.' > '.$data['index'];

		$this->_ossup($object, $filePath);

		echo " ...ok\n";

		//这个true是假的 需要判断是不是真的上传了
		return true;
	}
	/**
	 * Short description.
	 *
	 * return 
	 */
	private static function _alioss_init(){
	}
	/**
	 * Short description.
	 *
	 * return 
	 */
	private function _ossup($object, $filePath){
		self::_alioss_init();
		
		try {
			$ossClient = new OssClient(self::$alioss_accessKeyId, self::$alioss_accessKeySecret, self::$alioss_endpoint);
			$ossClient->uploadFile(self::$alioss_bucket, $object, $filePath);
		} catch (OssException $e) {
			print $e->getMessage();
		}

		return true;
	}

	/**
	 * Short description.
	 *
	 * return 
	 */
	private function _download_history(){
		$history = $this->_oss_get_in_memory(self::FILEHISTORYFILEPATH);

		return $history;
	}


	/**
	 * Short description.
	 *
	 * return 
	 */
	private function _upload_history(){

		$object = self::FILEHISTORYFILEPATH;

		$filePath = __DIR__.'/'.self::FILEHISTORYFILEPATH;


		$this->_ossup($object, $filePath);
		return $history;
	}

	/**
	 * Short description.
	 *
	 * return 
	 */
	private function _oss_get_in_memory($object){
		self::_alioss_init();
		
		
		try{
			$ossClient = new OssClient(self::$alioss_accessKeyId, self::$alioss_accessKeySecret, self::$alioss_endpoint);

			$exist = $ossClient->doesObjectExist(self::$alioss_bucket, $object);
			if($exist){
				return $ossClient->getObject(self::$alioss_bucket, $object);
			}
		} catch(OssException $e) {
			printf(__FUNCTION__ . ": FAILED\n");
			printf($e->getMessage() . "\n");
			return;
		}

		return false;
	}
		
}
$obj = new JYBACK();
$obj->main();
?>