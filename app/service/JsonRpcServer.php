<?php
declare(strict_types=1);

namespace app\service;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use think\exception\HttpResponseException;
use think\Response;

/**
 * JSON-RPC 2.0 Server
 *
 * 支持标准 JSON-RPC 2.0 协议：
 *   - 请求格式：{"jsonrpc":"2.0","method":"service.method","params":[...],"id":1}
 *   - 批量请求：[{...}, {...}]
 *   - 命名参数：{"jsonrpc":"2.0","method":"service.method","params":{"arg":"value"},"id":1}
 *   - 错误码定义遵循 JSON-RPC 2.0 规范
 *
 * 服务暴露规则（method 命名）：
 *   WxWorkService::createGroupChat()  → "wxwork.createGroupChat"
 *   JuhebotService::sendMessage()     → "juhebot.sendMessage"
 *   AutoGroupService::handleNewCustomer() → "autogroup.handleNewCustomer"
 */
class JsonRpcServer
{
    /** @var array<string, object> 服务实例缓存 */
    private array $services = [];

    /** @var array<string, string> 服务名→类名映射 */
    private array $serviceMap = [];

    private array $config;

    // JSON-RPC 2.0 标准错误码
    public const ERR_PARSE_ERROR         = -32700; // 解析错误
    public const ERR_INVALID_REQUEST     = -32600; // 无效请求
    public const ERR_METHOD_NOT_FOUND    = -32601; // 方法不存在
    public const ERR_INVALID_PARAMS      = -32602; // 参数错误
    public const ERR_INTERNAL_ERROR      = -32603; // 内部错误

    public const ERR_SERVER              = -32000; // 服务器错误（自定义起始）

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('rpc') ?? [];

        if (!empty($this->config['services'])) {
            foreach ($this->config['services'] as $name => $class) {
                $this->serviceMap[$name] = is_numeric($name) ? $class : $name;
                $this->services[$this->normalizeServiceName($class)] = null;
            }
        }
    }

    // ─────────────────────────────────────────────
    // 公共入口
    // ─────────────────────────────────────────────

    /**
     * 处理 JSON-RPC 请求
     * 自动识别单请求 / 批量请求，返回 think\Response 对象
     */
    public function handle(): ?array
    {
        try {
            $raw = $this->readInput();
            $data = $this->parseJson($raw);

            if ($data === null) {
                return $this->errorResponse(null, self::ERR_PARSE_ERROR, 'Parse error: invalid JSON');
            }

            $isBatch = is_array($data) && array_keys($data) === range(0, count($data) - 1);

            if ($isBatch) {
                $results = [];
                foreach ($data as $item) {
                    $results[] = $this->handleRequest($item);
                }
                return $results;
            }

            return $this->handleRequest($data);
        } catch (\Throwable $e) {
            LogService::error([
                'tag'     => 'JsonRpc',
                'message' => 'RPC 处理异常',
                'data'    => [
                    'error'   => $e->getMessage(),
                    'trace'   => $this->config['server']['debug'] ? $e->getTraceAsString() : null,
                ],
            ]);

            return $this->errorResponse(null, self::ERR_INTERNAL_ERROR, $e->getMessage());
        }
    }

    /**
     * 单个请求处理
     */
    public function handleRequest(?array $request): ?array
    {
        if ($request === null) {
            return null;
        }

        // 验证 JSON-RPC 版本
        if (($request['jsonrpc'] ?? '') !== '2.0') {
            return $this->errorResponse($request['id'] ?? null, self::ERR_INVALID_REQUEST, 'Invalid jsonrpc version, must be "2.0"');
        }

        if (empty($request['method']) || !is_string($request['method'])) {
            return $this->errorResponse($request['id'] ?? null, self::ERR_INVALID_REQUEST, 'Missing or invalid method');
        }

        $method = trim($request['method']);
        $params = $request['params'] ?? [];
        $id     = $request['id'] ?? null;

        // 认证校验
        if (($this->config['auth']['enable'] ?? false)) {
            $passed = $this->authenticate();
            if (!$passed) {
                return $this->errorResponse($id, -32001, 'Unauthorized: missing or invalid RPC auth key');
            }
        }

        // 解析 method → service.class
        $parsed = $this->parseMethod($method);
        if ($parsed === null) {
            return $this->errorResponse($id, self::ERR_METHOD_NOT_FOUND, "Method not found: {$method}");
        }

        [$serviceName, $methodName] = $parsed;

        // 获取或实例化服务
        $service = $this->resolveService($serviceName);
        if ($service === null) {
            return $this->errorResponse($id, self::ERR_METHOD_NOT_FOUND, "Service not found: {$serviceName}");
        }

        if (!method_exists($service, $methodName)) {
            return $this->errorResponse($id, self::ERR_METHOD_NOT_FOUND, "Method not found: {$serviceName}.{$methodName}");
        }

        // 调用前的日志
        LogService::info([
            'tag'     => 'JsonRpc',
            'message' => 'RPC 调用',
            'data'    => [
                'method' => $method,
                'params' => $params,
            ],
        ]);

        // 绑定参数并调用
        $boundParams = $this->bindParams($service, $methodName, $params);
        if ($boundParams === null) {
            return $this->errorResponse($id, self::ERR_INVALID_PARAMS, 'Invalid parameters for method');
        }

        try {
            $result = $service->$methodName(...$boundParams);

            return [
                'jsonrpc' => '2.0',
                'result'  => $result,
                'id'     => $id,
            ];
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($id, self::ERR_INVALID_PARAMS, $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->errorResponse($id, self::ERR_SERVER, $e->getMessage());
        } catch (\Throwable $e) {
            LogService::error([
                'tag'     => 'JsonRpc',
                'message' => 'RPC 方法执行异常',
                'data'    => [
                    'method' => $method,
                    'error'  => $e->getMessage(),
                    'trace'  => $this->config['server']['debug'] ? $e->getTraceAsString() : null,
                ],
            ]);

            return [
                'jsonrpc' => '2.0',
                'error'   => [
                    'code'    => self::ERR_INTERNAL_ERROR,
                    'message' => $this->config['server']['debug'] ? $e->getMessage() : 'Internal error',
                ],
                'id' => $id,
            ];
        }
    }

    // ─────────────────────────────────────────────
    // 服务注册
    // ─────────────────────────────────────────────

    /**
     * 注册一个服务实例或类
     * @param string $name 服务别名
     * @param object|string $service 服务实例或类名
     */
    public function register(string $name, $service): void
    {
        $this->serviceMap[$name] = $name;
        $this->services[$name] = is_object($service) ? $service : null;
    }

    /**
     * 获取已注册的方法列表（用于 introspection）
     */
    public function listMethods(): array
    {
        $methods = [];

        foreach ($this->serviceMap as $name => $className) {
            $instance = $this->resolveService($name);
            if ($instance === null) {
                continue;
            }

            $rc = new ReflectionClass($instance);
            foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || strpos($method->getName(), '__') === 0) {
                    continue;
                }
                $methods[] = [
                    'service'  => $name,
                    'method'   => $method->getName(),
                    'fullName' => "{$name}.{$method->getName()}",
                    'docComment' => trim($method->getDocComment() ?: ''),
                ];
            }
        }

        return $methods;
    }

    // ─────────────────────────────────────────────
    // 内部方法
    // ─────────────────────────────────────────────

    private function readInput(): string
    {
        $input = file_get_contents('php://input');
        return $input !== false ? $input : '';
    }

    private function parseJson(string $raw): ?array
    {
        if (trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return $data;
    }

    private function parseMethod(string $method): ?array
    {
        // 支持 "service.method" 和 "service_sub.method" 两种格式
        // 例: "wxwork.createGroupChat" → service="wxwork", method="createGroupChat"
        // 例: "WxWorkService.getAccessToken" → service="WxWorkService", method="getAccessToken"
        if (str_contains($method, '.')) {
            [$service, $action] = explode('.', $method, 2);
            return [trim($service), trim($action)];
        }

        // 尝试通过前缀匹配
        $service = $this->findServiceByPrefix($method);
        if ($service !== null) {
            $action = substr($method, strlen($service));
            return [$service, $action];
        }

        return null;
    }

    private function findServiceByPrefix(string $method): ?string
    {
        foreach ($this->serviceMap as $name) {
            if (strpos($method, $name) === 0 && strlen($method) > strlen($name)) {
                return $name;
            }
        }
        return null;
    }

    private function resolveService(string $name): ?object
    {
        // 优先从缓存获取已实例化的服务
        if (isset($this->services[$name]) && $this->services[$name] !== null) {
            return $this->services[$name];
        }

        // 查找原始类名（支持别名映射）
        $className = $this->findServiceClass($name);
        if ($className === null) {
            return null;
        }

        // 构造完整类名
        $fullClass = $this->normalizeClassName($className);

        if (!class_exists($fullClass)) {
            LogService::error([
                'tag'     => 'JsonRpc',
                'message' => 'RPC 服务类不存在',
                'data'    => ['class' => $fullClass],
            ]);
            return null;
        }

        // 通过反射 + 已知服务映射解决构造函数的依赖注入
        $instance = $this->createInstanceWithDependencies($fullClass);
        $this->services[$name] = $instance;

        return $instance;
    }

    /**
     * 通过反射和已知服务映射创建带依赖的实例
     */
    private function createInstanceWithDependencies(string $className): object
    {
        $constructor = (new ReflectionClass($className))->getConstructor();

        // 无构造函数（WxWorkService、JuhebotService）
        if ($constructor === null) {
            return new $className();
        }

        $params = $constructor->getParameters();
        if (empty($params)) {
            return new $className();
        }

        $args = [];
        foreach ($params as $param) {
            $type = $param->getType();
            $arg = null;

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                // 已注册的服务类型 → 递归解析（循环依赖时用 null 兜底）
                if ($this->isRegisteredServiceType($typeName)) {
                    $arg = $this->resolveDependency($typeName);
                }
            }

            // 无法注入时，有默认值则使用默认值，否则抛异常让外层捕获
            if ($arg === null && $param->isDefaultValueAvailable()) {
                $arg = $param->getDefaultValue();
            } elseif ($arg === null) {
                throw new \RuntimeException(
                    "Cannot resolve dependency: {$param->getName()} for {$className}"
                );
            }

            $args[] = $arg;
        }

        return new $className(...$args);
    }

    /**
     * 判断类名是否为已注册的 RPC 服务类型
     */
    private function isRegisteredServiceType(string $typeName): bool
    {
        foreach ($this->config['services'] ?? [] as $value) {
            $class = $this->normalizeClassName(is_string($value) ? $value : '');
            if ($class !== '' && (new ReflectionClass($class))->getName() === (new ReflectionClass($typeName))->getName()) {
                return true;
            }
        }
        return false;
    }

    /**
     * 解析依赖服务实例
     */
    private function resolveDependency(string $typeName): ?object
    {
        // 按注册名查找
        foreach ($this->config['services'] ?? [] as $name => $value) {
            $mapKey = is_numeric($name) ? $value : $name;
            $fullClass = $this->normalizeClassName(is_string($value) ? $value : $name);
            if ((new ReflectionClass($fullClass))->getName() === (new ReflectionClass($typeName))->getName()) {
                return $this->resolveService($mapKey);
            }
        }
        // 兜底：直接用反射实例化（要求目标类无参数构造函数）
        return new $typeName();
    }

    private function findServiceClass(string $name): ?string
    {
        foreach ($this->config['services'] ?? [] as $key => $value) {
            $mapKey = is_numeric($key) ? $value : $key;
            if ($mapKey === $name) {
                return is_string($value) ? $value : $name;
            }
        }
        return null;
    }

    private function normalizeServiceName(string $className): string
    {
        // WxWorkService → wxwork
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));
    }

    private function normalizeClassName(string $name): string
    {
        if (str_contains($name, '\\')) {
            return $name;
        }
        return "app\\service\\{$name}";
    }

    /**
     * 根据 Reflection 参数绑定传入的值
     *
     * JSON-RPC params 支持两种格式：
     *   位置参数： [arg1, arg2]
     *   命名参数： {"arg1": value1, "arg2": value2}
     *
     * 自动处理默认值、可选参数
     */
    private function bindParams(object $service, string $methodName, $params): ?array
    {
        $refMethod = new ReflectionMethod($service, $methodName);
        $refParams = $refMethod->getParameters();

        // 空参数 → 空数组
        if ($params === null || $params === []) {
            return $this->validateRequiredParams($refParams, []);
        }

        // 位置参数 [arg1, arg2]
        if (is_array($params) && array_keys($params) === range(0, count($params) - 1)) {
            return $this->bindPositional($refParams, $params);
        }

        // 命名参数 {"arg": value}
        if (is_array($params)) {
            return $this->bindNamed($refParams, $params);
        }

        return null;
    }

    private function bindPositional(array $refParams, array $params): ?array
    {
        $result = [];
        $refCount = count($refParams);

        for ($i = 0; $i < $refCount; $i++) {
            $ref = $refParams[$i];

            if (array_key_exists($i, $params)) {
                $value = $params[$i];
            } elseif ($ref->isDefaultValueAvailable()) {
                $value = $ref->getDefaultValue();
            } else {
                return null; // 缺少必需参数
            }

            $result[] = $this->castValue($value, $ref);
        }

        return $result;
    }

    private function bindNamed(array $refParams, array $params): ?array
    {
        $result = [];

        foreach ($refParams as $ref) {
            $paramName = $ref->getName();

            if (array_key_exists($paramName, $params)) {
                $result[] = $this->castValue($params[$paramName], $ref);
            } elseif ($ref->isDefaultValueAvailable()) {
                $result[] = $ref->getDefaultValue();
            } else {
                return null;        
            }
        }

        return $result;
    }

    private function validateRequiredParams(array $refParams, array $params): ?array
    {
        $positional = array_keys($params) === range(0, count($params) - 1);

        foreach ($refParams as $idx => $ref) {
            if ($positional) {
                if (!array_key_exists($idx, $params) && !$ref->isDefaultValueAvailable()) {
                    return null;
                }
            } else {
                if (!array_key_exists($ref->getName(), $params) && !$ref->isDefaultValueAvailable()) {
                    return null;
                }
            }
        }

        return $positional ? $params : $this->bindNamed($refParams, $params);
    }

    /**
     * 类型转换：JSON decode 后 PHP 类型与目标类型对齐
     * 注意：PHP 本身参数类型宽松，这里只对明确声明了类型的做 cast
     */
    private function castValue($value, ReflectionParameter $param)
    {
        $type = $param->getType();

        if ($type === null || !$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        $typeName = $type->getName();

        switch ($typeName) {
            case 'string':
                return (string) $value;
            case 'int':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'bool':
                return (bool) $value;
            case 'array':
                return (array) $value;
            default:
                return $value;
        }
    }

    private function authenticate(): bool
    {
        $key = $this->config['auth']['key'] ?? '';
        $headerKey = $_SERVER['HTTP_X_RPC_KEY'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (strpos($headerKey, 'Bearer ') === 0) {
            $headerKey = substr($headerKey, 7);
        }

        return $headerKey !== '' && hash_equals($key, $headerKey);
    }

    private function errorResponse($id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
            'id' => $id,
        ];
    }

    private function json(array $data): Response
    {
        $debug = $this->config['server']['debug'] ?? false;
        $response = Response::create($data, 'json');
        $response->header(['Content-Type' => 'application/json; charset=utf-8']);
        return $response;
    }
}
