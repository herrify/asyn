<?php
/**
 * @name CurlRequest php 请求类
 * @license 基于curl 实现， 可模拟多线程任务
 */
class CurlRequest
{
	/**
	 * @name 成员变量
	 */
	// param
	protected $url; 		// url参数
	protected $data; 		// data参数
	// request
	protected $request_url 				= ''; 		// 请求地址
	protected $request_data 			= array();	// 请求参数
	protected $request_timeout 			= 30; 		// 请求超时时间(单位秒)  0为无限等待

	/**
	 * @name 请求地址
	 * @param $url
	 */
	public function url($url)
	{
		$this->url 	= $url;

		$parseUrl	= parse_url($url);  
		$this->request_url 	= '';
		$this->request_url 	.= $parseUrl['scheme']=='https' ? 'https://' : 'http://';
		$this->request_url 	.= $parseUrl['host'];
		$this->request_url 	.= $parseUrl['port'] ? ':'.$parseUrl['port'] : ':80';
		$this->request_url 	.= $parseUrl['path'];
		parse_str($parseUrl['query'], $parseStr);
		$this->request_data	= array_merge($this->request_data, $parseStr);

		return $this;
	}

	/**
	 * @name 请求数据
	 * @param $data 为数组
	 */
	public function data($data)
	{
		$this->request_data = array_merge($this->request_data, $data);
		return $this;
	}

	/**
	 * @name 请求超时时间
	 * @param $timeout 超时， 当timeout 为0 或 flase时， 类为多线程执行
	 */
	public function timeout($timeout)
	{
		// $this->request_timeout 	= (int)$timeout==0 ? 1 : (int)$timeout;
		$this->request_timeout 	= (int)$timeout;
		return $this;
	}

	/**
	 * @name get请求
	 * @return mixed [status, data]
	 */
	public function get()
	{
		$returnData;
		// 1. 初始化
		$ch = curl_init();
		// 2. 设置选项，包括URL
		$url = $this->request_url.'?'.http_build_query($this->request_data);
		curl_setopt($ch, CURLOPT_HTTPGET, 1); 			// 请求类型 get
		curl_setopt($ch, CURLOPT_URL, $url); 			// 请求地址
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 	// 将curl_exec()获取的信息以文件流的形式返回,不直接输出。
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->request_timeout); 	// 连接等待时间
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->request_timeout); 			// curl允许执行时间

		// 3. 执行并获取返回内容
		$output = curl_exec($ch);
		if ($output === false)
		{
		    $returnData['status'] 	= 0;
			$returnData['data'] 	= curl_error($ch);
		}
		else
		{
			$returnData['status'] 	= 1;
			$returnData['data'] 	= $output;			
		}
		// 4. 释放curl句柄
		curl_close($ch);
		return $returnData;
	}
	/**
	 * @name post请求
	 * @return mixed [status, data]
	 */
	public function post()
	{
		$returnData;
		// 1. 初始化
		$ch = curl_init();
		// 2. 设置选项，包括URL
		curl_setopt($ch, CURLOPT_POST, 1); 					// 请求类型 post
		curl_setopt($ch, CURLOPT_URL, $this->request_url);	// 请求地址
		curl_setopt($ch, CURLOPT_POSTFIELDS, $this->request_data);	// 请求数据		
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 		// 将curl_exec()获取的信息以文件流的形式返回,不直接输出。
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->request_timeout); 	// 连接等待时间
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->request_timeout); 			// curl允许执行时间
		// 3. 执行并获取返回内容
		$output = curl_exec($ch);
		if ($output === false)
		{
		    $returnData['status'] 	= 0;
			$returnData['data'] 	= curl_error($ch);
		}
		else
		{
			$returnData['status'] 	= 1;
			$returnData['data'] 	= $output;			
		}
		// 4. 释放curl句柄
		curl_close($ch);
		return $returnData;
	}
}