<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\AppDispatch;

class HttpRoute extends AppDispatch {
	/**
	 * $request 请求对象
	 * @var null
	 */
	public $request = null;

	/**
	 * $response
	 * @var null
	 */
	public $response = null;

	/**
	 * $require_uri 请求的url
	 * @var null
	 */
	public $require_uri = null;

	/**
	 * $config 配置值
	 * @var null
	 */
	public $config = null;

	/**
	 * $extend_data 额外请求数据
	 * @var null
	 */
	public $extend_data = null;

    /**
     * @var int pathinfo 模式
     */
    private $route_model_pathinfo = 1;

    /**
     * @var int 参数路由模式
     */
    private $route_model_query_params = 2;

    /**
     * @var string 控制器后缀
     */
    private $controller_suffix = 'Controller';

    /**
     * $default_route 
     * @var string
     */
    private $default_route = 'Index/index';

    /**
	 * $deny_actions 禁止外部直接访问的action
	 * @var array
	 */
	protected static $deny_actions = ['__construct','_beforeAction','_afterAction','__destruct'];

	/**
	 * __construct
	 */
	public function __construct($extend_data = null) {
		parent::__construct();
		$this->request = Application::getApp()->request;
		$this->require_uri = $this->request->server['PATH_INFO'];

		$this->response = Application::getApp()->response;
		$this->config = Application::getApp()->config;

		$this->extend_data = $extend_data;
	}

	/**
	 * dispatch 路由调度
	 * @return mixed
	 */
	public function dispatch() {
	    if(!isset($this->config['route_model']) || !in_array($this->config['route_model'], [$this->route_model_pathinfo, $this->route_model_query_params])) {
            $this->config['route_model'] = 1;
        }

		if($this->config['route_model'] == $this->route_model_pathinfo) {
			if($this->require_uri == '/' || $this->require_uri == '//') {
			    if(isset($this->config['default_route']) && !empty($this->config['default_route'])) {
                    $this->require_uri = '/'.trim($this->config['default_route'], '/');
                }else {
                	$this->require_uri = '/'.$this->default_route;
                }
			}
			$route_uri = trim($this->require_uri,'/');
			if($route_uri) {
				$route_params = explode('/', $route_uri);
				$count = count($route_params);
				switch($count) {
					case 1 : 
						$module = null;
						$controller = $route_params[0];
						$action = 'index';
					break;
					case 2 : 
						$module = null;
						// Controller/Action模式
						list($controller, $action) = $route_params;
					break;
					case 3 : 
						// Module/Controller/Action模式
						list($module, $controller, $action) = $route_params;
					break;	
				}
			}
		}else if($this->config['route_model'] == $this->route_model_query_params) {
			$module = (isset($this->request->get['m']) && !$this->request->get['m']) ? $this->request->get['m'] : null;
			$controller = $this->request->get['c'];
			$action = isset($this->request->get['t']) ? $this->request->get['t'] : 'index';
			if($module) {
				$this->require_uri = '/'.$module.'/'.$controller.'/'.$action;
			}else {
				$this->require_uri = '/'.$controller.'/'.$action;
			}
		}

		// 重新设置一个route
		$this->request->server['ROUTE'] = $this->require_uri;
		// route参数组数
		$this->request->server['ROUTE_PARAMS'] = [];
		// 定义禁止直接外部访问的方法
		if(in_array($action, self::$deny_actions)) {
            Application::getApp()->setEnd();
			return $this->response->end("{$action}() method is not be called");
		}
		if($module) {
			// route参数数组
			$this->request->server['ROUTE_PARAMS'] = [3, [$module, $controller, $action]];
			// 调用
			$this->invoke($module, $controller, $action);
			
		}else {
			// route参数数组
			$this->request->server['ROUTE_PARAMS'] = [2, [$controller, $action]];
			// 调用 
			$this->invoke($module = null, $controller, $action);
		}
		return null;
	}

	/**
	 * invoke 路由与请求实例处理
	 * @param  string  $module
	 * @param  string  $controller
	 * @param  string  $action
     * @throws \Exception
	 * @return boolean
	 */
	public function invoke($module = null, $controller = null, $action = null) {
		// 匹配控制器文件
		$controller = $controller.$this->controller_suffix;
		if(!isset($this->config['app_namespace'])) {
            $this->config['app_namespace'] = APP_NAME;
        }
        $filePath = APP_PATH.DIRECTORY_SEPARATOR.$controller.'.php';
        if($module) {
            $filePath = APP_PATH.DIRECTORY_SEPARATOR.'Module'.DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.$controller.'.php';
            // 访问类的命名空间
			$class = $this->config['app_namespace'].'\\'.'Module'.'\\'.$module.'\\'.$controller;
			// 不存在请求类文件
			if(!self::isExistRouteFile($class)) {
				if(!is_file($filePath)) {
					$this->response->status(404);
					$this->response->header('Content-Type','application/json; charset=UTF-8');
                    // 使用配置的NotFound类
                    if(isset($this->config['not_found_handle']) && is_array($this->config['not_found_handle'])) {
                        $class = $this->redirectNotFound();
                    }else {
                        Application::getApp()->setEnd();
                        return $this->response->end(json_encode([
                            'ret'=> 404,
                            'msg'=> $filePath.' is not exit!',
                            'data'=>''
                        ]));
                    }
				}else {
					self::setRouteFileMap($class);
				}
			}

		}else {
			// 访问类的命名空间
			$class = $this->config['app_namespace'].'\\'.'Controller'.'\\'.$controller;
			if(!self::isExistRouteFile($class)) {
				$filePath = APP_PATH.DIRECTORY_SEPARATOR.'Controller'.DIRECTORY_SEPARATOR.$controller.'.php';
				if(!is_file($filePath)) {
					$this->response->status(404);
					$this->response->header('Content-Type','application/json; charset=UTF-8');
                    // 使用配置的NotFound类
                    if(isset($this->config['not_found_handle']) && is_array($this->config['not_found_handle'])) {
                        // 访问类的命名空间
                        $class = $this->redirectNotFound();
                    }else {
                        Application::getApp()->setEnd();
                        return $this->response->end(json_encode([
                            'ret'=> 404,
                            'msg'=> $filePath.' is not exit!',
                            'data'=>''
                        ]));
                    }
				}else {
					self::setRouteFileMap($class);
				}
			}
		}

		// 创建控制器实例
		$controllerInstance = new $class();
        // 提前执行_beforeAction函数
		$isContinueAction = $controllerInstance->_beforeAction();
        if($isContinueAction === false) {
            $this->response->status(403);
            $this->response->header('Content-Type','application/json; charset=UTF-8');
            $query_string = isset($this->request->server['QUERY_STRING']) ? '?'.$this->request->server['QUERY_STRING'] : '';
            if(isset($this->request->post) && !empty($this->request->post)) {
                $post = json_encode($this->request->post,JSON_UNESCAPED_UNICODE);
                $msg = "call {$class}::_beforeAction return false, forbiden continue call {$class}::{$action}, please checkout it ||| ".$this->request->server['REQUEST_URI'].$query_string.' post_data:'.$post;
            }else {
                $msg = "call {$class}::_beforeAction return false, forbiden continue call {$class}::{$action}, please checkout it ||| ".$this->request->server['REQUEST_URI'].$query_string;
            }
            Application::getApp()->setEnd();
            $this->response->end(json_encode([
                'ret'=> 403,
                'msg'=> $msg,
                'data'=>''
            ]));
            return false;
        }
        // 创建reflector对象实例
		$reflector = new \ReflectionClass($controllerInstance);
		if($reflector->hasMethod($action)) {
			$method = new \ReflectionMethod($controllerInstance, $action);
			if($method->isPublic() && !$method->isStatic()) {
				try{
					if($this->extend_data) {
						//$method->invoke($controllerInstance, $this->extend_data);
                        $controllerInstance->{$action}($this->extend_data);
					}else {
                        //$method->invoke($controllerInstance);
                        $controllerInstance->{$action}();
					}
		        }catch (\Exception $e) {
		            $method = new \ReflectionMethod($controllerInstance, '__call');
		            $method->invokeArgs($controllerInstance, [$action, '']);
		        }catch(\Throwable $t) {
				    $query_string = isset($this->request->server['QUERY_STRING']) ? '?'.$this->request->server['QUERY_STRING'] : '';
				    if(isset($this->request->post) && !empty($this->request->post)) {
				        $post = json_encode($this->request->post,JSON_UNESCAPED_UNICODE);
                        $msg = 'Fatal error: '.$t->getMessage().' on '.$t->getFile().' on line '.$t->getLine(). ' ||| '.$this->request->server['REQUEST_URI'].$query_string.' post_data:'.$post;
                    }else {
                        $msg = 'Fatal error: '.$t->getMessage().' on '.$t->getFile().' on line '.$t->getLine(). ' ||| '.$this->request->server['REQUEST_URI'].$query_string;
                    }
                    // 记录错误异常
                    $exceptionClass = Application::getApp()->getExceptionClass();
                    $exceptionClass::shutHalt($msg);
                    Application::getApp()->setEnd();
				    $this->response->end(json_encode([
                        'ret' => 500,
                        'msg' => $msg,
                        'data' => ''
                    ],JSON_UNESCAPED_UNICODE));
		        }
			}else {
                Application::getApp()->setEnd();
                $msg = "class method {$class}::{$action} is static or private, protected property, can't be Instance object called";
				return $this->response->end(json_encode([
					'ret'=> 500,
					'msg'=> $msg,
					'data'=>''
				]));
			}
		}else {
            Application::getApp()->setEnd();
            $msg = "Controller file '{$filePath}' is exited, but has undefined {$class}::{$action} method";
            $this->response->status(404);
			$this->response->header('Content-Type','application/json; charset=UTF-8');
            return $this->response->end(json_encode([
                'ret'=> 404,
                'msg'=> $msg,
                'data'=>''
            ]));
		}
	}

	/**
	 * redirectNotFound 重定向至NotFound类
	 * @return   array
	 */
	public function redirectNotFound($call_func = null) {
		if(isset($this->config['not_found_handle'])) {
			// 重定向至NotFound类
			list($namespace, $action) = $this->config['not_found_handle'];
            $controller = @array_pop(explode('\\', $namespace));
            // 重新设置一个NotFound类的route
            $this->request->server['ROUTE'] = '/'.$controller.'/'.$action;

            return trim(str_replace('/', '\\', $namespace), '/');
		}
	}

	/**
	 * isExistRouteFile 判断是否存在请求的route文件
	 * @param    string  $route  请求的路由uri
	 * @return   boolean
	 */
	public static function isExistRouteFile($route) {
		return isset(self::$routeCacheFileMap[$route]) ? self::$routeCacheFileMap[$route] : false;
	}

	/**
	 * setRouteFileMap 缓存路由的映射
	 * @param   string  $route  请求的路由uri
	 * @return  void
	 */
	public static function setRouteFileMap($route) {
		self::$routeCacheFileMap[$route] = true;
	}

	/**
	 * resetRouteDispatch 重置路由调度,将实际的路由改变请求,主要用在boostrap()中
	 * @param   string  $route  请求的路由uri
	 * @return  void
	 */
	public static function resetRouteDispatch($route) {
	    $route = trim($route,'/');
        Application::getApp()->request->server['PATH_INFO'] = '/'.$route;
    }

}