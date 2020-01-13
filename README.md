# Tea语言 (Tealang)

```Tea
echo "Hello，世界！"
```

## 简要介绍

Tea语言是一门新的计算机编程语言，采用强规范设计（规范即语法），拥有简约的强类型系统和单元模块体系，支持类型推断，支持面向对象和函数式编程，语法精炼简洁。其目标是成为一个友好的，支持多端开发的编程语言，并尽量支持常用编程语言生态，让开发者可以继续使用已有工作成果。目前通过编译生成PHP代码运行，可调用PHP库，预计后期版本将支持一些其它编程语言。

为了对使用者更为友好，让使用者可以更专注于创意实现，和降低学习成本，本语言尽量保持了常用编程语言的风格和习惯，并在此基础上对语法进行优化，力求让代码编写体验更轻松自然。

Tea语言由创业者Benny设计开发，潘春孟（高级工程师/架构师）与刘景能（计算机博士）参与了早期设计与测试使用。

## 语言特性

- 强规范，规范即语法，简洁清晰
- 简约的，带类型推断的强类型系统，编译时将进行类型推断与检查
- 独特的XView类型，可方便的用于Web视图组件开发
- 有限的类型兼容性，数据操作便捷而不失安全性
- 内置类型被封装成伪对象，支持对象成员风格调用，如：```"Some string".length```
- 内置单元模块（Unit）体系，基于单元模块组织程序，和访问控制
- 无普通全局变量，变量作用域最高为普通函数层级，无需担心全局变量污染问题
- 字符串处理语法灵活、简单而强大
- 流程控制语法灵活、简约、统一
- 运算符规则简单有规律，易于记忆
- 支持Lambda表达式和普通函数，函数是一等公民
- 支持类和接口，接口可带默认实现，面向对象特性简单而不失强大
- 支持普通函数、普通常量、类和接口的访问控制（public/internal）
- 支持类/接口成员的访问控制（public/internal/protected/private）
- 通过编译生成目标语言代码的方式运行

## 安装和使用

- 安装PHP 7.2+，编译器和编译输出的程序需要PHP 7.2或以上版本运行环境

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
	php tea/bin/tea --init myproject.com/hello
	```

## 致谢

Tea语言从众多优秀的编程语言中获取到设计灵感，主要包括（排名不分先后）：PHP、Hack、JavaScript、TypeScript、Python、Swift、Kotlin、Go、Rust、Ruby、Java、C#、Pascal、C、Julia等。在此向设计和维护这些编程语言的大师们致敬。

