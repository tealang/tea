
# Tealang

Tealang is a programming language that emphasizes coding conventions, a simple strong type system, and a modular system. It supports both object-oriented and functional programming paradigms while providing a concise syntax.

The strong type system ensures that variables and expressions have explicit types, and type checking is done statically, which enhances code reliability and maintainability.

The modular system allows code to be organized and managed in modules, each with its own scope and interface. This promotes code reusability, scalability, and facilitates teamwork and code organization.

The concise syntax aims to provide a language that is easy to read and write, reducing cognitive load for developers. By maintaining consistency with the syntax style and conventions of common programming languages, reduces the learning curve and makes it easier for developers to get started.

# Install

1. Install PHP 8.1
2. Add the directory where the PHP execution file is located to the environment variable.
3. Clone the Tealang repository. `git clone https://github.com/tealang/tea.git`

# Use

1. Switch to the work directory and execute the compile command:
```bash
tea/bin/teac tea/tests/examples
```

2. Create a new package, such as:
```bash
php tea/bin/teac --init myproject/hello
```
