## 🌟 简介

Bing首页每日都会更新一张来自世界各地的精美图片。通过 **[www.tianlingzi.top](http://www.tianlingzi.top)** 提供的API链接，您可以简单、快速地获取这些栩栩如生的每日壁纸。这些壁纸每日自动更新，无论是作为网站背景还是电脑桌面壁纸，都是非常不错的选择，为您的数字生活增添一份色彩。

## 🌐 项目主页

您可以直接在浏览器中输入以下地址，访问本项目主页，体验每日壁纸服务：

- **项目主页：** [https://www.tianlingzi.top/bing](https://www.tianlingzi.top/bing/)

更多项目介绍和技术细节，请访问本人博客介绍页面：

- **博客介绍：** [https://www.tianlingzi.top/archives/275/](https://www.tianlingzi.top/archives/278/)

## 💡 代码开源

开源，是一种互联网精神。本着取之于民用之于民的原则，本项目代码已在Github上完全开源，欢迎大家查阅、学习和贡献：

- **GitHub仓库：** <https://github.com/tianlingzi/bing>
- **上游GitHub仓库：** <https://github.com/xyz66882/bing>
- **上游GitHub仓库：** <https://github.com/mike126126/bing>

您可以将GitHub代码克隆一份到您自己的主机（服务器）上，并绑定好域名，即可部署并访问您自己的Bing每日壁纸服务。

**重要提示：**

- **PHP直接输出图片** 的方式，图片流量会经过您的服务器，因此速度会受限于服务器的带宽和性能。
- **跳转至Bing图片直链** 的方式使用。这种方式直接输出Bing图片的原始直链，图片访问不占用您服务器的流量，速度不受服务器影响，通常更快、更好用。
- `www.tianlingzi.top` 服务本身已启用阿里云ESA全球加速，理论上直接使用API的速度也相当快。

如果您不想自己部署代码，可以直接使用 `www.tianlingzi.top` 提供的API服务，方便快捷。

## 🔗 bing体验链接：

本站API服务：

- <https://www.tianlingzi.top/bing/1920x1080.php>
- <https://www.tianlingzi.top/bing/1366x768.php>
- <https://www.tianlingzi.top/bing/m.php>
- <https://www.tianlingzi.top/bing/uhd.php>

本站API服务（随机壁纸）：

- <https://www.tianlingzi.top/bing/rand_1920x1080.php>
- <https://www.tianlingzi.top/bing/rand_1366x768.php>
- <https://www.tianlingzi.top/bing/rand_m.php>
- <https://www.tianlingzi.top/bing/rand_uhd.php>

跳转至Bing图片直链

- <https://www.tianlingzi.top/bing/1920x1080_302.php>
- <https://www.tianlingzi.top/bing/1366x768_302.php>
- <https://www.tianlingzi.top/bing/m_302.php>
- <https://www.tianlingzi.top/bing/uhd_302.php>

php直接输出图片链接不提供体验链接，由于php直接输出图片，会占用服务器流量，跳转至Bing图片直链不会不占用服务器的流量。

## 🛠️ 使用方法

本API接口的链接可以直接作为图片URL链接来使用，方便地嵌入到您的网页或应用中。
![屏幕截图 2026-08-09 045424.png](https://www.tianlingzi.top/usr/uploads/2026/08/3165048057.png)
![image.png](https://www.tianlingzi.top/usr/uploads/2026/08/85845098.png)
以下是两种主要的使用方式：

### 🖼️ PHP直接输出图片

这种方式会通过您的服务器代理图片输出，适用于需要服务器端处理或缓存的场景。

```html
<!-- 1080P高清壁纸 -->
<img src="https://www.tianlingzi.top/bing/1920x1080.php" alt="Bing每日壁纸1080P高清" />

<!-- 1366x768分辨率壁纸 -->
<img src="https://www.tianlingzi.top/bing/1366x768.php" alt="Bing每日图片" />

<!-- 手机超高清竖版壁纸 -->
<img src="https://www.tianlingzi.top/bing/m.php" alt="Bing每日壁纸手机超高清" />

<!-- UHD超高清原图壁纸 -->
<img src="https://www.tianlingzi.top/bing/uhd.php" alt="Bing每日壁纸UHD超高清原图" />
```

### ➡️ 跳转至Bing图片直链

这种方式会通过HTTP 302重定向到Bing官方的图片直链，不占用您的服务器流量，速度更快。

```html
<!-- 1080P高清壁纸直链 -->
<img src="https://www.tianlingzi.top/bing/1920x1080_302.php" alt="Bing每日壁纸1080P高清" />

<!-- 1366x768分辨率壁纸直链 -->
<img src="https://www.tianlingzi.top/bing/1366x768_302.php" alt="Bing每日图片" />

<!-- 手机超高清竖版壁纸直链 -->
<img src="https://www.tianlingzi.top/bing/m_302.php" alt="Bing每日壁纸手机超高清" />

<!-- UHD超高清原图壁纸直链 -->
<img src="https://www.tianlingzi.top/bing/uhd_302.php" alt="Bing每日壁纸UHD超高清原图" />
```

