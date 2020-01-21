# Tea语言 (Tealang)

```Tea
// 来自深圳的问候
echo "Hello, 世界"
```

## 简单对比

```Tea
// Tea
var days = ['Monday', 'Tuesday', 'Wednesday']
var items String.Array = []   	// supported type declarations
for i, day in days {
	items[] = "${i + 1}: $day"  // supports all expression interpolations
}

echo items.join(', ')
```

```PHP
<?php
// PHP
$days = ['Monday', 'Tuesday', 'Wednesday'];
$items = [];  	// do not supported type declarations
foreach ($days as $i => $day) {
	$items[] = ($i + 1) . ": $day";  // only supported variable / array-value / object-property interpolations
}

echo implode(', ', $items), "\n";
```

```javascript
// JavaScript(ES2015)
let days = ['Monday', 'Tuesday', 'Wednesday']
let items = []   // do not supported type declarations
for (i = 0; i < days.length; i++) {
	let day = days[i]
	items.push(`${i + 1}: ${day}`)  // do not supported interpolations on version < ES2015
}

console.log(items.join(', '))
```

## 简要介绍

Tea语言是一种新的计算机编程语言，采用强规范设计（规范即语法），拥有简约的强类型系统和单元模块体系，支持类型推断，支持面向对象和函数式编程，语法精炼简洁。其目标是成为一个友好的，支持多端开发的编程语言，并尽量支持常用编程语言生态，让开发者可以继续使用已有工作成果。目前通过编译生成PHP代码运行，可调用PHP库，可用于Web服务器端开发。预计后续将支持编译生成部分其它编程语言。

Tea语言非常注重语法的友好性，通过对语法进行优化设计，希望开发者可以更轻松自然的编写代码，可以更专注于创意实现。也尽量保持了常用编程语言的语法风格和习惯，以降低学习成本。

Tea语言项目最早开始于19年2月份，主要由创业者Benny设计与开发，潘春孟（高级工程师/架构师）与刘景能（计算机博士）参与了设计与使用。项目初衷为用于实现自研产品功能和提升团队内部开发效率，最初特性较少，在完善和优化后，于19年12月初首次发布开源。

## 语言特性

- 强规范，规范即语法，简洁清晰
- 简约的，带类型推断的强类型系统，编译时将进行类型推断与检查
- 独特的XView类型，可方便的用于Web视图组件开发
- 有限的类型兼容性，数据操作便捷而不失安全性
- 内置类型被封装成伪对象，支持对象成员风格调用，如：```"Some string".length```
- 内置单元模块（Unit）体系，基于单元模块组织程序，和访问控制
- 无普通全局变量，变量作用域最高为普通函数层级，无需担心全局变量污染问题
- 字符串处理语法灵活、简单而强大
- 流程控制语法灵活、简约、统一（所有都支持catch/finally分支，for支持else分支）
- 运算符规则简单有规律，易于记忆
- 支持Lambda表达式和普通函数，函数是一等公民
- 支持类和接口，接口可带默认实现，面向对象特性简单而不失强大
- 支持普通函数、普通常量、类和接口的访问控制（public/internal）
- 支持类/接口成员的访问控制（public/internal/protected/private）
- 通过编译生成目标语言代码的方式运行

## 安装和使用

- 安装PHP 7.2+，编译器和编译输出的程序依赖PHP 7.2或以上版本运行环境

- 安装好PHP后，将PHP执行文件所在目录添加到操作系统环境变量

- 将Tea语言项目克隆到本地（或其它方式下载，但需保证Tea语言项目的目录名称为tea）
	```sh
	# clone with the Git client
	git clone https://github.com/tealang/tea.git
	```
	
- 将当前目录切换到tea的上级目录中，执行如下命令即可编译本文档程序：
	```sh
	# use the normal method
	php tea/bin/tea tea/docs
	```
	
- 如使用Mac或Linux系统，可使用Shebang方式，如：
	```sh
	# lets the scripts could be execute
	chmod +x tea/bin/*
	# use the Shebang method
	tea/bin/tea tea/docs
	```
	
- 在tea/dist目录中可看到编译结果
	
- 创建或初始化一个新的Unit，如：
	
	```sh
	php tea/bin/tea --init myproject/hello
	```

## 致谢

Tea语言从众多优秀的编程语言中获取到设计灵感，主要包括（排名不分先后）：PHP、Hack、JavaScript、TypeScript、Python、Swift、Kotlin、Go、Rust、Ruby、Java、C#、Pascal、C、Julia等。在此向设计和维护这些项目的大师们致敬。

