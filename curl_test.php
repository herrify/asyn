<?php
/**
 * @name CurlRequest php 请求类
 * @license 基于curl 实现， 可模拟多线程任务
 */
class CurlTest
{
	public function curlopen($url,$time=10){
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_TIMEOUT_MS,$time);//设置最长执行时间
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

		$data = curl_exec($ch);
		$curl_errno = curl_errno($ch);
		$curl_error = curl_error($ch);
		curl_close($ch);
	}

	public function asyn_send($params){
		$ip = '127.0.0.1';
    	$url = "/WIFISYS_MC/wifi/noticeOffLine?".$params;

    	$fp = fsockopen($ip,80,$errno,$errstr,5);

    	if (!$fp) {
    		echo "$errstr ($errno)<br> \r\n";
    	}
    	$end = "\r\n";
    	$input = "GET $url $end";
    	$input .= "Host: $ip$end";
    	$input .= "Connection: Close$end";
    	$input .= "$end";
    	fputs($fp,$input);
    	$html = '';
	    while (!feof($fp)) {
	        $html.=fgets($fp);
	    }
	    fclose($fp);
	    $this->writelog($html);
	    echo $html;
	}


	public function writelog($message){
		$path = TMP_DIR.'error.txt';
	    $handler = fopen($path, 'w+b');
	    if ($handler) {
	        $success = fwrite($handler, $message);
	        fclose($handler);
    	}
	}

}