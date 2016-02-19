<?php
/**
 * PHP memcache 环形队列类
 * 原作者 LKK/lianq.net
 * 修改 FoxHunter
 * 因业务需要只保留的队列中的Pop和Push,修改过期时间为0即永久
 */
class MQueue
{
 public static $client;
 private $expire; //过期时间,秒,1~2592000,即30天内
 private $sleepTime; //等待解锁时间,微秒
 private $queueName; //队列名称,唯一值
 private $retryNum; //尝试次数
 private $MAXNUM; //最大队列容量
 private $canRewrite; //是否可以覆写开关，满出来的内容从头部开始覆盖重写原来的数据
 private $HEAD; //下一步要进入的指针位置
 private $TAIL; //下一步要进入的指针位置
 private $LEN; //队列现有长度
 const LOCK_KEY = '_Fox_MQ_LOCK_'; //锁存储标示
 const LENGTH_KEY = '_Fox_MQ_LENGTH_'; //队列现长度存储标示
 const VALU_KEY = '_Fox_MQ_VAL_'; //队列键值存储标示
 const HEAD_KEY = '_Fox_MQ_HEAD_'; //队列HEAD指针位置标示
 const TAIL_KEY = '_Fox_MQ_TAIL_'; //队列TAIL指针位置标示
 /*
  * 构造函数
  * 对于同一个$queueName，实例化时必须保障构造函数的参数值一致，否则pop和push会导队列顺序混乱
  */
 public function __construct($queueName = '', $maxqueue = 1, $canRewrite = false, $expire = 0, $config = '')
 {
  if (empty($config)) {
   self::$client = memcache_pconnect('127.0.0.1', 11211);
  } elseif (is_array($config)) { //array('host'=>'127.0.0.1','port'=>'11211')
   self::$client = memcache_pconnect($config['host'], $config['port']);
  } elseif (is_string($config)) { //"127.0.0.1:11211"
   $tmp   = explode(':', $config);
   $conf['host'] = isset($tmp[0]) ? $tmp[0] : '127.0.0.1';
   $conf['port'] = isset($tmp[1]) ? $tmp[1] : '11211';
   self::$client = memcache_pconnect($conf['host'], $conf['port']);
  }
  if (!self::$client)
   return false;
  ignore_user_abort(true); //当客户断开连接,允许继续执行
  set_time_limit(0); //取消脚本执行延时上限
  $this->access  = false;
  $this->sleepTime = 1000;
  $expire   = (empty($expire)) ? 0 : (int) $expire + 1;
  $this->expire  = $expire;
  $this->queueName = $queueName;
  $this->retryNum = 20000;
  $this->MAXNUM  = $maxqueue != null ? $maxqueue : 1;
  $this->canRewrite = $canRewrite;
  $this->getHeadAndTail();
  if (!isset($this->HEAD) || empty($this->HEAD))
   $this->HEAD = 0;
  if (!isset($this->TAIL) || empty($this->TAIL))
   $this->TAIL = 0;
  if (!isset($this->LEN) || empty($this->LEN))
   $this->LEN = 0;
 }
 //获取队列首尾指针信息和长度
 private function getHeadAndTail()
 {
  $this->HEAD = (int) memcache_get(self::$client, $this->queueName . self::HEAD_KEY);
  $this->TAIL = (int) memcache_get(self::$client, $this->queueName . self::TAIL_KEY);
  $this->LEN = (int) memcache_get(self::$client, $this->queueName . self::LENGTH_KEY);
 }
 // 利用memcache_add原子性加锁
 private function lock()
 {
  if ($this->access === false) {
   $i = 0;
   while (!memcache_add(self::$client, $this->queueName . self::LOCK_KEY, 1, false, $this->expire)) {
    usleep($this->sleepTime);
    @$i++;
    if ($i > $this->retryNum) { //尝试等待N次
     return false;
     break;
    }
   }
   return $this->access = true;
  }
  return false;
 }
 //更新头部指针指向,指向下一个位置
 private function incrHead()
 {
  //$this->getHeadAndTail(); //获取最新指针信息 ,由于本方法体均在锁内调用，其锁内已调用了此方法，本行注释
  $this->HEAD++; //头部指针下移
  if ($this->HEAD >= $this->MAXNUM) {
   $this->HEAD = 0; //边界值修正
  }
  ;
  $this->LEN--; //Head的移动由Pop触发，所以相当于数量减少
  if ($this->LEN < 0) {
   $this->LEN = 0; //边界值修正
  }
  ;
  memcache_set(self::$client, $this->queueName . self::HEAD_KEY, $this->HEAD, false, $this->expire); //更新
  memcache_set(self::$client, $this->queueName . self::LENGTH_KEY, $this->LEN, false, $this->expire); //更新
 }
 //更新尾部指针指向，指向下一个位置
 private function incrTail()
 {
  //$this->getHeadAndTail(); //获取最新指针信息，由于本方法体均在锁内调用，其锁内已调用了此方法，本行注释
  $this->TAIL++; //尾部指针下移
  if ($this->TAIL >= $this->MAXNUM) {
   $this->TAIL = 0; //边界值修正
  }
  ;
  $this->LEN++; //Head的移动由Push触发，所以相当于数量增加
  if ($this->LEN >= $this->MAXNUM) {
   $this->LEN = $this->MAXNUM; //边界值长度修正
  }
  ;
  memcache_set(self::$client, $this->queueName . self::TAIL_KEY, $this->TAIL, false, $this->expire); //更新
  memcache_set(self::$client, $this->queueName . self::LENGTH_KEY, $this->LEN, false, $this->expire); //更新
 }
 // 解锁
 private function unLock()
 {
  memcache_delete(self::$client, $this->queueName . self::LOCK_KEY);
  $this->access = false;
 }
 //判断是否满队列
 public function isFull()
 {
  //外部直接调用的时候由于没有锁所以此处的值是个大概值，并不很准确，但是内部调用由于在前面有lock，所以可信
  if ($this->canRewrite)
   return false;
  return $this->LEN == $this->MAXNUM ? true : false;
 }
 //判断是否为空
 public function isEmpty()
 {
  //外部直接调用的时候由于没有锁所以此处的值是个大概值，并不很准确，但是内部调用由于在前面有lock，所以可信
  return $this->LEN == 0 ? true : false;
 }
 public function getLen()
 {
  //外部直接调用的时候由于没有锁所以此处的值是个大概值，并不很准确，但是内部调用由于在前面有lock，所以可信
  return $this->LEN;
 }
 /*
  * push值
  * @param mixed 值
  * @return bool
  */
 public function push($data = '')
 {
  $result = false;
  if (empty($data))
   return $result;
  if (!$this->lock()) {
   return $result;
  }
  $this->getHeadAndTail(); //获取最新指针信息
  if ($this->isFull()) { //只有在非覆写下才有Full概念
   $this->unLock();
   return false;
  }
  if (memcache_set(self::$client, $this->queueName . self::VALU_KEY . $this->TAIL, $data, MEMCACHE_COMPRESSED, $this->expire)) {
   //当推送后，发现尾部和头部重合（此时指针还未移动），且右边仍有未由Head读取的数据，那么移动Head指针，避免尾部指针跨越Head
   if ($this->TAIL == $this->HEAD && $this->LEN >= 1) {
    $this->incrHead();
   }
   $this->incrTail(); //移动尾部指针
   $result = true;
  }
  $this->unLock();
  return $result;
 }
 /*
  * Pop一个值
  * @param [length] int 队列长度
  * @return array
  */
 public function pop($length = 0)
 {
  if (!is_numeric($length))
   return false;
  if (!$this->lock())
   return false;
  $this->getHeadAndTail();
  if (empty($length))
   $length = $this->LEN; //默认读取所有
  if ($this->isEmpty()) {
   $this->unLock();
   return false;
  }
  //获取长度超出队列长度后进行修正
  if ($length > $this->LEN)
   $length = $this->LEN;
  $data = $this->popKeyArray($length);
  $this->unLock();
  return $data;
 }
 /*
  * pop某段长度的值
  * @param [length] int 队列长度
  * @return array
  */
 private function popKeyArray($length)
 {
  $result = array();
  if (empty($length))
   return $result;
  for ($k = 0; $k < $length; $k++) {
   $result[] = @memcache_get(self::$client, $this->queueName . self::VALU_KEY . $this->HEAD);
   @memcache_delete(self::$client, $this->queueName . self::VALU_KEY . $this->HEAD, 0);
   //当提取值后，发现头部和尾部重合（此时指针还未移动），且右边没有数据，即队列中最后一个数据被完全掏空，此时指针停留在本地不移动，队列长度变为0
   if ($this->TAIL == $this->HEAD && $this->LEN <= 1) {
    $this->LEN = 0;
    memcache_set(self::$client, $this->queueName . self::LENGTH_KEY, $this->LEN, false, $this->expire); //更新
    break;
   } else {
    $this->incrHead(); //首尾未重合，或者重合但是仍有未读取出的数据，均移动HEAD指针到下一处待读取位置
   }
  }
  return $result;
 }
 /*
  * 重置队列
  * * @return NULL
  */
 private function reset($all = false)
 {
  if ($all) {
   memcache_delete(self::$client, $this->queueName . self::HEAD_KEY, 0);
   memcache_delete(self::$client, $this->queueName . self::TAIL_KEY, 0);
   memcache_delete(self::$client, $this->queueName . self::LENGTH_KEY, 0);
  } else {
   $this->HEAD = $this->TAIL = $this->LEN = 0;
   memcache_set(self::$client, $this->queueName . self::HEAD_KEY, 0, false, $this->expire);
   memcache_set(self::$client, $this->queueName . self::TAIL_KEY, 0, false, $this->expire);
   memcache_set(self::$client, $this->queueName . self::LENGTH_KEY, 0, false, $this->expire);
  }
 }
 /*
  * 清除所有memcache缓存数据
  * @return NULL
  */
 public function memFlush()
 {
  memcache_flush(self::$client);
 }
 public function clear($all = false)
 {
  if (!$this->lock())
   return false;
  $this->getHeadAndTail();
  $Head = $this->HEAD;
  $Length = $this->LEN;
  $curr = 0;
  for ($i = 0; $i < $Length; $i++) {
   $curr = $this->$Head + $i;
   if ($curr >= $this->MAXNUM) {
    $this->HEAD = $curr = 0;
   }
   @memcache_delete(self::$client, $this->queueName . self::VALU_KEY . $curr, 0);
  }
  $this->unLock();
  $this->reset($all);
  return true;
 }
}