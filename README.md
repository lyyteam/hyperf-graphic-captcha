# lyyteam/hyperf-graphic-captcha

适用于 **Hyperf 3.1+** 的图形验证码组件。基于 PHP GD 与 Hyperf 官方组件（Config / Cache / HttpServer）实现。

验证码答案以 bcrypt 哈希形式写入 **Cache**（TTL = `expire`），客户端通过 `key` 完成校验。

---

## 目录

- [环境要求](#环境要求)
- [安装](#安装)
- [工作原理](#工作原理)
- [配置说明](#配置说明)
- [助手函数](#助手函数)
- [场景一：Web 表单（图片模式）](#场景一web-表单图片模式)
- [场景二：前后端分离 API](#场景二前后端分离-api)
- [场景三：验证器规则](#场景三验证器规则)
- [场景四：命名配置组](#场景四命名配置组)
- [场景五：算术 / 中文验证码](#场景五算术--中文验证码)
- [常见问题](#常见问题)

---

## 环境要求

- PHP >= 8.1，并启用 `ext-gd`
- Hyperf ^3.1
- 项目已安装并配置 `hyperf/cache`（文件 / Redis 等驱动均可）

---

## 安装

```bash
composer require lyyteam/hyperf-graphic-captcha
```

可选：发布配置文件（仅此一个文件，非必须；不发布则使用内置默认值）：

```bash
php bin/hyperf.php vendor:publish lyyteam/hyperf-graphic-captcha
```

发布后文件路径：`config/autoload/captcha.php`。

### 自动能力

组件启动后自动完成：

| 能力 | 说明 |
| --- | --- |
| 路由 | `GET /captcha`、`GET /captcha/{config}` |
| 验证规则 | 若已安装 `hyperf/validation`，注册规则名 `captcha` |

无需再手写路由文件。

---

## 工作原理

```
1. 调用 create() / 访问 /captcha
2. 生成明文答案 → bcrypt 哈希 → 写入 Cache（键：cache_prefix + key，TTL = expire）
3. 将 key 交给客户端：
   - 图片模式：Set-Cookie + 响应头 X-Captcha-Key
   - API 模式：JSON 字段 key
4. 用户提交验证码内容（及 key）
5. check() / captcha_check() 用 key 取哈希并校验；**无论成败都会删除** Cache 条目（一次性）
```

**key 解析优先级**（未显式传入时）：

1. 请求参数 `captcha_key`
2. 请求头 `X-Captcha-Key`
3. Cookie（默认名 `graphic_captcha_key`）

---

## 配置说明

| 配置项 | 说明 | 默认 |
| --- | --- | --- |
| `length` | 验证码位数 | `5` |
| `code_set` | 非中文字符集 | `2345678abcdefhijkmnpqrstuvwxyzABCDEFGHJKLMNPQRTUVWXY` |
| `expire` | 过期秒数（Cache TTL） | `1800` |
| `use_zh` | 是否中文验证码 | `false` |
| `zh_set` | 中文字符池 | 内置常用汉字 |
| `math` | 是否算术验证码（如 `12 + 3 =`） | `false` |
| `use_img_bg` | 是否使用 JPEG 背景图 | `false` |
| `font_size` | 字体大小（px） | `25` |
| `curve_strength` | 曲线干扰强度 `0–10`（`0` 关闭；`5` 默认观感；越大越难辨认） | `5` |
| `noise_strength` | 杂点干扰强度 `0–10`（`0` 关闭；`5` 默认观感；越大越难辨认） | `5` |
| `fontttf` | 指定字体文件名；空则随机 | `''` |
| `bg` | 背景 RGB | `[243, 251, 254]` |
| `image_h` / `image_w` | 高宽；`0` 自动计算 | `0` |
| `alpha` | 背景透明度 `0–127` | `0` |
| `api` | `true` 时返回 JSON（key + base64 图） | `false` |
| `route_prefix` | 图片 URL 前缀 | `/captcha` |
| `cache_prefix` | Cache 键前缀 | `graphic_captcha:` |
| `cookie_name` | 图片模式下写入的 Cookie 名 | `graphic_captcha_key` |

完整示例见下方 [场景四](#场景四命名配置组)。

---

## 助手函数

| 函数 | 说明 |
| --- | --- |
| `captcha(?string $config = null)` | 生成验证码；默认返回 PNG 响应，`api=true` 时返回数组 |
| `captcha_src(?string $config = null)` | 返回验证码图片 URL，如 `/captcha`、`/captcha/verify` |
| `captcha_img(string $id = '', string $domid = '')` | 返回可点击刷新的 `<img>` HTML |
| `captcha_check(string $value, ?string $cache_key = null)` | 校验用户输入；`$cache_key` 可省略（自动解析） |

也可直接注入使用：

```php
use Lyyteam\HyperfGraphicCaptcha\Captcha;
use Psr\Container\ContainerInterface;

public function example(ContainerInterface $container)
{
    $captcha = $container->make(Captcha::class);

    // 生成
    $result = $captcha->create();           // 默认配置
    $result = $captcha->create('verify');   // 命名配置组

    // 校验
    $ok = $captcha->check($code);                 // 自动解析 key
    $ok = $captcha->check($code, $captcha_key);   // 显式传入 key
}
```

---

## 场景一：Web 表单（图片模式）

适用于服务端渲染页面、同源 Cookie 可用的场景。保持默认 `api => false`。

### 1. 页面展示

Blade / Twig / 原生模板均可：

```html
<form method="post" action="/login">
    <input type="text" name="username" placeholder="用户名" />
    <input type="password" name="password" placeholder="密码" />

    <!-- 方式 A：助手函数一键输出（点击图片可刷新） -->
    <div>{!! captcha_img() !!}</div>

    <!-- 方式 B：自行拼 img -->
    <!-- <img src="{!! captcha_src() !!}" alt="captcha"
         onclick="this.src='{!! captcha_src() !!}?'+Math.random()" /> -->

    <input type="text" name="captcha" placeholder="验证码" required />
    <button type="submit">登录</button>
</form>
```

指定 DOM id：

```php
echo captcha_img('', 'login-captcha');
// <img ... id='login-captcha' ... />
```

使用命名配置组：

```php
echo captcha_img('verify');
// 图片地址为 /captcha/verify
```

### 2. 控制器校验

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

class LoginController
{
    public function login(RequestInterface $request, ResponseInterface $response)
    {
        $code = (string) $request->input('captcha', '');

        // 同源下 Cookie 已带 key，无需手动传第二参数
        if (! captcha_check($code)) {
            return $response->json([
                'code' => 422,
                'message' => '验证码错误或已过期',
            ]);
        }

        // 验证码通过，继续登录逻辑...
        return $response->json(['code' => 0, 'message' => 'ok']);
    }
}
```

> 图片响应会设置 Cookie（默认 `graphic_captcha_key`）与响应头 `X-Captcha-Key`。浏览器再次提交同站表单时会自动带上 Cookie。

---

## 场景二：前后端分离 API

适用于 Vue / React / App 等。建议使用命名配置组开启 `api`，避免影响默认图片路由。

### 1. 配置

`config/autoload/captcha.php`：

```php
<?php

declare(strict_types=1);

return [
    'length' => 5,
    'expire' => 300,

    // API 专用配置组（组名勿与根选项 api 布尔值混淆，推荐用 api_captcha）
    'api_captcha' => [
        'length' => 4,
        'api' => true,
        'curve_strength' => 5,
        'noise_strength' => 5,
        'expire' => 300,
    ],
];
```

> 说明：根级 `'api' => true/false` 是选项；命名组请使用其它名称（如 `api_captcha`）。组件会把关联数组当成命名组，不会覆盖根级布尔选项。

### 2. 获取验证码

请求内置路由（配置组名为 `api_captcha`）：

```http
GET /captcha/api_captcha
```

或在控制器中：

```php
public function captcha(ContainerInterface $container)
{
    return $container->make(Captcha::class)->create('api_captcha');
    // 等价：return captcha('api_captcha');
}
```

响应示例：

```json
{
  "key": "a1b2c3d4e5f67890...",
  "img": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA..."
}
```

### 3. 前端示例（fetch）

```javascript
let captchaKey = '';

async function loadCaptcha() {
  const res = await fetch('/captcha/api_captcha');
  const data = await res.json();
  captchaKey = data.key;
  document.getElementById('captcha-img').src = data.img;
}

async function submitLogin() {
  const body = {
    username: document.getElementById('username').value,
    password: document.getElementById('password').value,
    captcha: document.getElementById('captcha').value,
    captcha_key: captchaKey, // 必须带回
  };

  const res = await fetch('/api/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  console.log(await res.json());
}

loadCaptcha();
```

也可把 key 放在请求头：

```javascript
headers: {
  'Content-Type': 'application/json',
  'X-Captcha-Key': captchaKey,
}
```

### 4. 后端校验

```php
public function login(RequestInterface $request, ResponseInterface $response)
{
    $code = (string) $request->input('captcha', '');
    $key = (string) $request->input('captcha_key', '');

    // 推荐显式传 key；也可依赖 Header / Cookie 自动解析
    if ($key === '' || ! captcha_check($code, $key)) {
        return $response->json([
            'code' => 422,
            'message' => '验证码错误或已过期',
        ], 422);
    }

    return $response->json(['code' => 0, 'message' => 'ok']);
}
```

---

## 场景三：验证器规则

需已安装 `hyperf/validation`。规则名：`captcha`。

### Web（Cookie 自动带 key）

```php
use Hyperf\Validation\Contract\ValidatorFactoryInterface;

public function login(RequestInterface $request, ValidatorFactoryInterface $validationFactory)
{
    $validator = $validationFactory->make($request->all(), [
        'username' => 'required|string',
        'password' => 'required|string',
        'captcha' => 'required|captcha',
    ], [
        'captcha.captcha' => '验证码错误',
    ]);

    if ($validator->fails()) {
        return ['code' => 422, 'errors' => $validator->errors()];
    }

    // 通过...
}
```

### API（需提交 captcha_key）

```php
$validator = $validationFactory->make($request->all(), [
    'captcha' => 'required|captcha',
    'captcha_key' => 'required|string',
]);
```

规则内部会读取同一份请求数据中的 `captcha_key`；若无该字段，则回退到 Header / Cookie。

---

## 场景四：命名配置组

可在同一配置文件中定义多套风格，通过路由或 `create($name)` 切换。

```php
<?php

declare(strict_types=1);

return [
    // 默认（根配置）— 对应 GET /captcha
    'length' => 5,
    'expire' => 1800,
    'curve_strength' => 5,
    'noise_strength' => 5,
    'api' => false,

    // 登录页短验证码 — GET /captcha/login（偏低干扰，便于人工输入）
    'login' => [
        'length' => 4,
        'expire' => 300,
        'curve_strength' => 2,
        'noise_strength' => 1,
    ],

    // 接口用 — GET /captcha/mobile
    'mobile' => [
        'length' => 4,
        'api' => true,
        'expire' => 300,
        'font_size' => 28,
    ],

    // 算术题 — GET /captcha/math
    'math' => [
        'math' => true,
        'expire' => 600,
    ],
];
```

调用方式：

```php
captcha();              // 默认
captcha('login');       // 命名组 login
captcha_src('mobile');  // /captcha/mobile
captcha_check($code, $key);
```

浏览器访问：

- `http://your-host/captcha`
- `http://your-host/captcha/login`
- `http://your-host/captcha/math`

---

## 场景五：算术 / 中文验证码

### 算术验证码

用户看到类似 `12 + 3 =`，需填入计算结果 `15`。

```php
// config/autoload/captcha.php
return [
    'math' => true,
    // 或命名组：
    // 'calc' => ['math' => true, 'expire' => 600],
];
```

```php
if (! captcha_check($request->input('captcha'))) {
    // 失败
}
```

### 中文验证码

```php
return [
    'use_zh' => true,
    'length' => 4,
    // 可选：自定义汉字池
    // 'zh_set' => '天地玄黄宇宙洪荒',
];
```

需确保 `assets/zhttfs/` 下存在可用中文字体（组件已内置）。

---

## 常见问题

**Q: 校验总是失败？**  
- API 场景是否带回了获取验证码时的同一个 `key`？刷新验证码后旧 key 立即失效。  
- Cache 驱动是否正常？TTL 是否过短？  
- 是否大小写问题？组件会将输入转小写再比对（中文不受影响）。

**Q: 图片能显示但 Cookie 场景校验失败？**  
- 前后端是否跨域？跨域时 Cookie 默认不携带，请改用 API 模式并显式传 `captcha_key`。  
- 检查 `cookie_name` 是否被修改且前后一致。

**Q: 如何自定义字体？**  
将 `.ttf` / `.otf` 放入 `vendor/lyyteam/hyperf-graphic-captcha/assets/ttfs/`（或中文目录 `zhttfs/`），并在配置中设置 `fontttf => 'your.ttf'`。

**Q: 能否关闭或减弱干扰？**  

```php
return [
    // 完全关闭：强度设为 0
    'curve_strength' => 0,
    'noise_strength' => 0,
    // 减弱：强度 0–10，默认 5
    // 'curve_strength' => 2,
    // 'noise_strength' => 1,
    'use_img_bg' => false,
];
```

**Q: 验证成功后能否再次使用同一验证码？**  
不能。每次校验（无论成功或失败）都会删除对应 Cache，需重新获取，避免暴力尝试。

---

## License

MIT
