
# Tealang

```
echo "Hello, 世界"
```

---

## 01. Introduction

"Tea" is a programming language with a concise and powerful feature set.
It has a minimalist strong typing system and package system.
It supports type inference, object-oriented programming, and functional programming, with a clean and concise syntax.
Currently, it compiles to PHP code and can utilize PHP libraries.

For installation and usage instructions, please refer to the README.md file.

---

## 02. Basic Statements
- The Tea language compiler recognizes statements based on the syntactic validity.
- The components of a statement can be spread across multiple consecutive lines.
- When there are multiple statements in a single line of code, they need to be separated by semicolons.

```tea
// Outputting strings with trailing newline characters, supporting multiple items
echo 'How ', 'are ', 'you?'

// Output without trailing newline characters
print('Im fine!')

// Outputting a single newline character
echo
```

---

## 03. Types
- Simple types: String, Text, UInt, Int, Float, Bool
- Composite types: List<T>, Dict<T>, Object
- Other types: XView, Iterable, Callable, Any, Mixed, Null, Void
- `Array` and `T.Array` remain legacy-compatible input forms. New source should prefer `List<T>` and `Dict<T>`.
- `None` / `none` remain legacy aliases. New source should prefer `Null` / `null`.
- `Any` means any non-null value. `Mixed` means any value including null, matching PHP `mixed`.
- `Text` is an inline human-readable string type. It is not an HTML-safe type.
- The String type is designed to accept values of Int, UInt, Text, and XView types.
- UInt, when expressed as PHP code, is actually of type Int,
    and its maximum value is the same as PHP_INT_MAX.
- List is an indexed array,
    while Dict is an associative array (referred to as a dictionary).
- XView is a special type in the Tea language.
    It can accept blocks defined using HTML/XML tags or instances of classes
    that implement the IView interface as values.
- Iterable is an iterable type that can accept values of List, Dict types,
    or other iterable objects as values.
- Callable is a callable type that can accept regular functions and anonymous functions as values.
- `T?sentinel` expresses an invalidable type, such as `String?` or `UInt?-1`.
- `T!sentinel` expresses an excludable type, such as `String!''` or `UInt!0`.

```tea
// Any
var any Any
any = 1
any = []
any = 'abc'
// any = null  // error: use Mixed when null is allowed

var dynamic_value Mixed = null

// Pipe call
var valid_len = any::trim::strlen()
echo 'the valid strlen is: $valid_len'

// Runtime cast, using PHP-style syntax
var any_as_string = (String) any
var any_as_uint = (UInt) any

// Single-Quoted string literals, where characters like `\n\t\r` are treated as literal characters
var str String = 'Unescaped string\n'

// Double-Quoted String, where escape characters are processed
str = "Escaped string\n"

// Single-Quoted string interpolation
str_with_interpolation = 'Unescaped string with interpolation ${5 * 6}'

// Double-Quoted string interpolation
str_with_interpolation = "Escaped string with interpolation ${5 * 6}\n"

// String call
str.length
str.rune_length
str.copy(0, 3)
str.rune_copy(0, 3)

// UInt/Int/Float
var uint_num UInt = 123
var int_num Int = -123
var float_num Float = 123.01

// Bool
var bool Bool = true

// XView
var xview XView = <div>
    <p>Value is: {uint_num * 10}</p>
</div>

// List<Any>
var any_list List = [
    123,        // UInt
    'Hi',       // String
    false,      // Bool
    [1, 2, 3],  // List
]

// List<Int>
var int_list List<Int> = []
int_list = [-1, 10, 200]
int_list.length
int_list.copy(0, 2)

// Dict<String>, the keys support String|Int
var str_dict Dict<String> = [:]
str_dict = [
    'k1': 'value for string key "k1"',
    123: 'value for int key "123"'
]

// List<Dict<String>>
var str_dict_list List<Dict<String>> = [
    ['k0': 'v0', 'k1': 'v01'],
    str_dict
]

// Invalidable and Excludable sentinel types
var index UInt?-1 = find_index(items, 'name')
if index !== -1 {
    // index is narrowed to UInt here
}

func divide(total UInt, count UInt!0) Float {
    return (float) total / count
}
```

---

## 04. Modifiers
- Tea language has four modifiers: `public`, `internal`, `protected`, and `private`.
- All four modifiers can be used for the declaration of class members.
- `public` and `internal` can be used for the declaration of classes, interfaces,
    standalone constants, and standalone functions.
- Members decorated with `public` can be called from external packages.
- Members decorated with `internal` can only be called within the same package.
- Class members decorated with `protected` can be called by inheriting classes.
- Class members decorated with `private` can only be called within the same class.
---

## 05. Constants
- Constant declarations/definitions must begin with the `internal/public` modifier.
- The scope of a constant is package-level,
    and those declared as `public` can be imported and used by other packages.
- Constant names must be in uppercase, start with [A-Z_], and can include [A-Z0-9_].

```tea
internal STRING1 = 'abcdefg'
public MATH_PI = 3.1415926
public ARRAY_CONST = [1, 2]
```

---

## 06. Variables
- The Tea language does not support custom global variables, all variables are local in scope.
- Variable names must be lowercase and can consist of characters from a-z, 0-9, and underscore (_).
- Variables are declared using the `var` keyword and can be annotated with a type.
- Variables that are not annotated with a type but are assigned an initial value
    during declaration will be automatically inferred as the type of the assigned value.

```tea
// Declare a variable and assign an string value, will be inferred as a String type
var str1 = 'Hi!'

// Declare a variable of type String
var str2 String

// Declare a variable and assign an int value, will be inferred as an Int type
var var_without_decared = 123
```

---

## 07. Operators
- The priority and associativity of operators vary in different programming languages.
    Tea language attempts to simplify these rules and make them as natural as possible.
- Regarding associativity, prefix unary operators are right-associative,
    while binary operators (except the `??` operator) are left-associative.
- When nesting ternary operators, parentheses must be used to specify the order of operations.
    Therefore, associativity does not need to be considered.

|   Operators                           |   Description |
|------|---------------------------------------|---------------|
|   . [] () :: #                        |   Member accessing, Element accessing, Function Call or Class New, Pipe Call, Type Marking    |
|   - ~                                 |   Negation, Bitwise Not   |
|   * / % << >> &                       |   Multiplication, Division, Remainder |
|   + -                                 |   Addition, Subtraction   |
|   << >>                               |   Bitwise Shift Left, Bitwise Shift Right |
|   &                                   |   Bitwise And |
|   ^                                   |   Bitwise Xor |
|   \|                                  |   Bitwise Or  |
|   .*                                  |   String Repeat   |
|   .+                                  |   String/List Concat   |
|   <=> < <= > >= != !== == === is      |   Comparisons |
|   not                                 |   Logical Not |
|   and                                 |   Logical And |
|   or                                  |   Logical Or  |
|   ??                                  |   Null Coalescing |
|   condition ? exp1 : exp2             |   Ternary |
|   = *= /= += -= .= &= \|= ^= <<= >>=  |   Assignments |

```tea
// The concatenation operator is used for joining strings or lists.
// Many languages use the "+" operator for concatenation,
// but the semantics of adding numbers and concatenating strings/lists are different,
// which can be unclear in some scenarios.
// In Tea, the ".+" operator is used for concatenation.
// It has a lower precedence than mathematical and bitwise operators.
// String concatenation is rarely used in Tea because it provides a convenient string interpolation syntax.
var string_concat = 'abc' .+ 1 + 8 & 2 * 3  // equivalent to 'abc' .+ (1 + (8 & 2) * 3)
var list_concat = ['A', 'B'] .+ ['A1', 'C1'] // result: ['A', 'B', 'A1', 'C1']

// Runtime cast
var uint_from_non_negative_string = (UInt) '123'  // okay
// var uint_from_negative_string = (UInt) '-123'  // error
var str_from_uint = (String) 123
var str_from_float = (String) 123.123

// Type marking / noted type
var ex1 Any
var ex2 = ex1#Exception
var list = ex1#(List<String>)

// The "is" operator is used to check whether a variable is of a certain primitive type
// or an instance of a particular class.
1.1 is Int // false
1 is Int   // true
2 is UInt  // true
ErrorException('Some') is Exception  // true

// In the Tea language, the logical NOT operator has a lower precedence than comparison operators,
// which is different from other languages.
var not_result = not uint_num > 3  // Equivalent to: `not (uint_num > 3)`

// PHP-style ! is reserved for PHP-compatible input; Tea-authored code should use not.

// In the Tea language, when multiple nested ternary expressions are used,
// parentheses must be added, and the direction of association does not need to be considered.
var ternary_result = uint_num == 1 ? 'one' : (uint_num == 2 ? 'two' : (uint_num == 3 ? 'three' : 'other'))
```

---

## 08. Process control and exception handling structures
- The Tea language supports various control flow and exception handling structures,
    including if-else conditional branching, switch-case conditional branching,
    for-in iteration,
    for-to/downto range loops,
    while loops,
    and try-catch exception handling.
- The C-style for (;;;) loop statement is not supported, which is flexible but can lead to unexpected code.

```tea
a = 0
b = 1

if a {
    //
}
elseif b {}
else {}

for k, v in str_dict {
    // do sth.
}

for i = 0 to 9 {
    //
}

for i = 9 downto 0 step 2 {
    //
}

// A two-layer nested while loop with labels
i = 0
#outer_loop while 1 {
    #inner_loop while true {
        i += 1
    }
}
```

---

## 09. Functions
- Function declarations/definitions usually start with the internal/public modifier.
- The scope of the function is at the package level, and those declared as public can be introduced and used by other packages.
- The specification for function names in Tea language is lowercase, which must start with [a-z_] and can include [a-z0-9_]

```tea
internal fn0(str) {
    echo str
}

internal fn1(callee Callable) {
    callee('test call for the Callable argument')
}

fn1(fn0)

internal demo_function1(message String) {
    echo 'this function can only be called by local unit'
    return (a Int) => {
        echo 'the number is $a'
    }
}

demo_function1('hei')(123)

public demo_function2(message String = 'with a default value') {
    echo 'this function can be called by local or foreign packages'
}

public demo_function_with_a_return_type(some String) UInt {
    return some.length
}

// Function with callbacks
public demo_function_with_callbacks(some String, success (message String) String, failure (error) Void) String {
    var success_callback_result
    if success {
        success_callback_result = success('Success!')
    }

    if failure {
        failure('Some errors.')
    }

    return "the success callback result is: $success_callback_result"
}

// Normal call
var ret1 = demo_function_with_a_return_type('some data')

// Call with callbacks
var ret2 = demo_function_with_callbacks('some data', (message) => {
    echo message
    return 'Cool!'
}, (error) => {
    echo error
})
```

---

## 10. Classes and Interfaces
- Classes/Interfaces definitions must begin with the `internal` or `public` modifier.
- The scope of classes/interfaces is package-level, and those declared as `public` can be imported and used by other packages.
- Class/Interface names must be named in PascalCase style and can include [A-Za-z0-9_].
- The naming conventions for class/interface members are consistent with constants, variables, and functions.

```tea
public interface IDemo {
    // Constant
    CONST1 = 'This is a constant!'

    // Static Property
    static a_static_prop = "a static property."

    // Static Method
    static say_hello_with_static(name String = 'Benny') {
        echo "Hello, $name"
    }
}

internal interface DemoInterface {

    public message String = 'hei~'

    public set_message(message String)

    public get_message() String {
        return this.message
    }
}

internal DemoBaseClass {
    // Constructor
    construct(name String) {
        echo "Hey, $name, it is constructing..."
    }

    // Destructor
    destruct() {
        echo "it is destructing..."
    }

    protected a_protected_method() {
        //
    }
}

// extends / implements
public DemoPublicClass: DemoBaseClass, IDemo, DemoInterface {
    // implements for DemoInterface
    set_message(message String) {
        this.message = message
    }
}

// new
var object = DemoPublicClass('Benny')

// call instance method
object.set_message('some string')

// call static methods
object.say_hello_with_static()
DemoPublicClass.say_hello_with_static()
```

---

## 11. XView / XTag Interpolation

- XTag is Tea's HTML/XML-like view syntax.
- `{expr}` is HTML-escaping interpolation for text children and attributes.
- `${expr}` is normal/raw interpolation. Use it only for values that are already structured view output or explicitly safe.
- `Text` and legacy `Plain` are still escaped by default; they do not mean HTML-safe.
- `XView` / `IView` values can be inserted as structured view output.

```tea
var title Text = 'Hello <Tea>'

// Text child and attribute values are escaped.
var safe_view XView = <option value={title}>{title}</option>

// Raw insertion: only use for structured view output or explicitly safe output.
var child XView = <strong>{title}</strong>
var wrapper XView = <div>${child}</div>
```

---

## 12. Packages

- Each package has an independent namespace.
- Package metadata lives in `__package.th`.
- Public ABI is generated into `__public.th` and consumed by downstream packages.
- Packages are used to isolate the scope of program content from the outside.
    Constants, functions, classes, and interfaces declared as `public` can be called from external packages,
    while those declared as `internal` can only be called within the package.
- Use `use Package\Namespace { Symbol1, Symbol2 }` in package/header files to import public symbols.
- A dependency can be marked as trusted with `#[Trusted]` above the `use` statement.
    This only means its public scalar contracts are trusted for selected static checks such as XTag numeric attribute output.

```tea
// __package.th
namespace App

#[Trusted]
use Vendor\Library {
    Request,
    Response
}
```

---

## 13. Setting Program Files as Executable

- Write code to start execution of the program file using the #main block(function).
- The dependency loading statements will be automatically added to the compilation result of the current program file.
- The built-in library loading statements will be automatically added to the compilation result of the current package.
---

## 14. Using PHP Libraries without Namespaces

- Declare PHP constants, functions, classes, interfaces without namespaces using `extern` declarations for use in Tea language programs.
- Both PHP built-in and user-defined elements are supported.
- For user-defined elements, you need to add the relevant loading statements yourself.

```tea
// Declaring a PHP built-in constant
extern const PHP_VERSION String

// Declaring a PHP built-in function
extern func phpinfo() Void

// Declaring a PHP built-in class
extern class BadFunctionCallException: Exception {
    public getCode() Int
    public getMessage() String
}
```

---

## 15. Using PHP Libraries with Namespaces

- Create a package directory with a `__package.th` file that declares the namespace.
- `__public.th` is generated and should describe the public ABI consumed by downstream packages.
- Composer-style PHP libraries can be assessed by parsing package production source roots and generating cached headers when no hand-written `__public.th` exists.
- Existing trusted `__public.th` files should be consumed directly unless dependency validation is explicitly requested.

---

## 16. Attributes and Metadata

- Tea supports PHP 8 style attributes for structured metadata.
- Parser keeps attribute structure in the AST.
- Only checker/header consumers that understand a specific attribute may attach semantics to it.

```tea
#[TypeAssertion(value is Int)]
extern func is_int(value Mixed) Bool
```

---

## 17. Strict Identifier Guidelines

- Tea language promotes consistent naming conventions for identifiers,
    including variable names, constant names, function names, class names, etc. Case sensitivity applies.
- When no class/func/const is specified, the compiler will identify classes, functions,
    and constants based on the identifier style. Specifically:
  "snake_case" style will be recognized as function/method/property names,
  "UPPER_CASE" style will be recognized as constant names,
  "PascalCase" style will be recognized as class names.
- Using consistent coding conventions helps in reading and understanding code more quickly and clearly.

---

## 18. Strict Operator Guidelines

- White space characters (including spaces, newlines, and tabs) are part of the Tea language syntax and are used to separate syntax elements.
- Unary prefix operators must be preceded by at least one white space character.
- Binary operators for non-access operations (such as +, -, *, /) must have at least one white space character on both sides.
- There should be no white space characters before function parentheses "(" or array element access brackets "[".
- Strict operator coding conventions help maintain a consistent code style, leading to faster and clearer code comprehension.

---

## 19. Code Comments Syntax

Tea language supports two types of comment syntax for different scenarios:
- Single-line comments: `// inline comment`
- Multi-line comments: `/* multi-lines comments */`

---
