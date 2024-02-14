
# Tea

Tea is a programming language that emphasizes coding conventions, a simple strong type system, and a modular system. It supports both object-oriented and functional programming paradigms while providing a concise syntax.

The strong type system in Tea ensures that variables and expressions have explicit types, and type checking is done statically, which enhances code reliability and maintainability.

The modular system allows code to be organized and managed in modules, each with its own scope and interface. This promotes code reusability, scalability, and facilitates teamwork and code organization.

By supporting both object-oriented and functional programming paradigms, Tea offers different programming styles and tools to cater to diverse programming needs. Object-oriented programming emphasizes encapsulation, inheritance, and polymorphism, while functional programming emphasizes purity, immutability, and the use of higher-order functions. This enables developers to choose the appropriate programming style based on specific requirements and preferences.

Tea's concise syntax aims to provide a language that is easy to read and write, reducing cognitive load for developers. By maintaining consistency with the syntax style and conventions of common programming languages, Tea reduces the learning curve and makes it easier for developers to get started.

The slogan of the Tea language is: "Programming is like drinking tea."

# Install

1. Install PHP 8.1
2. Add the directory where the PHP execution file is located to the environment variable.
3. Clone the Tea repository. `git clone https://github.com/tealang/tea.git`

# Use

1. Switch to the work directory and execute the compile command:
```bash
# use the normal method
php tea/bin/tea tea/tests/examples
# if you use Mac or Linux, you can use shebang mode, such as:
tea/bin/tea tea/tests/examples
```

2. Create a new module, such as:
```bash
php tea/bin/tea --init myproject/hello
```
