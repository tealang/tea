# Tea语言（Tea Programming Language）
一种新的编程语言，其规范即是语法，简洁易用，并具有简单的类型系统和单元模块体系。（A new programming language, whose specification is grammar, is simple and easy to use, and has simple type system and unit module system.）

```Tea
// 来自深圳的问候
echo "世界你好！"
```

Tea语言目前有以下特点：
- 是一个强规范的编程语言（规范即语法），拥有精炼简洁的语法，简约的类型系统和单元模块体系
- 经过精心设计，力求让代码编写体验更轻松自然，让使用者可以更专注于创意实现
- 基于单元模块（Unit）组织程序文件，任何程序文件都必须包含在某个Unit中，可引入使用外部Unit
- 对字符串处理语法进行了特别设计，尤其方便用于WEB开发
- 类型系统支持类型推断，并在类型兼容性方面有一些特别处理
- 编译时将进行类型推断和语法检查，有助于提前发现相关问题
- 通过编译生成PHP代码运行，并可调用PHP库

Tea语言目标是打造一个支持多端开发的编程语言，并尽力支持已有的编程语言生态，以避免开发者浪费已有的工作成果。现阶段只支持用于Web服务器端开发。

Tea语言由创业者Benny设计开发，潘春孟（高级工程师/架构师）与刘景能（计算机博士）参与了早期设计与测试使用。

