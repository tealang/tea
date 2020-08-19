English | [简体中文](README.zh-hans.md) | [繁體中文](README.zh-hant.md)

# Tea

Tea is a programming language with strong specification design, simple strong type system and unit module system, supporting type inference, object-oriented and functional programming, and concise syntax. The goal is to become a friendly programming language that supports multi terminal development, and supports the common programming language ecology as far as possible, so that developers can continue to use the existing work results. At present, the PHP library can be called by translating it into PHP code, which can be used for web server-side development. It is expected that some other programming language ecology will be supported in the future.

Tea attaches great importance to the friendliness of syntax. By optimizing the syntax, it hopes that developers can write code more easily and naturally, and can focus more on creative implementation. It also tries to keep the grammar style and habit of common programming language to reduce the learning cost.

# Install and use

1. Install PHP 7.2 +
2. Add the directory where the PHP execution file is located to the environment variable.
3. Clone **Tea**.
```bash
# clone tea with the Git client
git clone https://github.com/tealang/tea.git
```
### Use
1. Switch to the program directory and execute the compile command:
```bash
# use the normal method
php tea/bin/tea file-name
```
2. If you use Mac or Linux, you can use shebang mode, such as:
```bash
# lets the scripts could be execute
chmod +x tea/bin/*
# use the Shebang method
tea/bin/tea tea/docs
```
3.The compilation results are located in the dist directory of the target unit. 
Create or initialize a new unit, such as:
```bash
php tea/bin/tea --init myproject/hello
```
