<?php
/**
 * Generate exercise PDF files for all exercises in the database.
 * Run: php generate_pdfs.php
 */

require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=learnadapt;charset=utf8mb4', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$exercises = $pdo->query('SELECT id, title, level, description FROM exercises')->fetchAll(PDO::FETCH_ASSOC);
$dir = __DIR__ . '/var/exercises';

if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

foreach ($exercises as $ex) {
    $id = $ex['id'];
    $title = $ex['title'];
    $level = $ex['level'];
    $desc = $ex['description'];
    $filename = "exercise_{$id}.pdf";
    $filepath = $dir . '/' . $filename;

    // Generate a proper PDF using raw PDF syntax
    $pdf = generatePdf($title, $level, $desc, $id);
    file_put_contents($filepath, $pdf);
    $size = filesize($filepath);

    // Update database with correct path
    $stmt = $pdo->prepare('UPDATE exercises SET pdf_path = ?, pdf_original_name = ?, pdf_size_bytes = ? WHERE id = ?');
    $stmt->execute([$filepath, $filename, $size, $id]);

    echo "Created: $filename ($size bytes)\n";
}

echo "\nDone! All " . count($exercises) . " PDFs generated.\n";

function generatePdf(string $title, string $level, string $description, int $id): string
{
    $lines = [];
    $lines[] = $title;
    $lines[] = '';
    $lines[] = 'Level: ' . $level;
    $lines[] = 'Exercise #' . $id;
    $lines[] = '';
    $lines[] = 'Description:';

    // Word-wrap description at ~80 chars
    foreach (explode("\n", wordwrap($description, 80, "\n", true)) as $l) {
        $lines[] = $l;
    }

    $lines[] = '';
    $lines[] = str_repeat('-', 60);
    $lines[] = '';

    // Add exercise-specific content
    $content = getExerciseContent($id, $title, $level);
    foreach (explode("\n", $content) as $l) {
        $lines[] = $l;
    }

    // Build PDF manually (valid PDF 1.4)
    $textLines = $lines;
    $pageWidth = 595;  // A4 width in points
    $pageHeight = 842; // A4 height in points
    $margin = 50;
    $fontSize = 11;
    $lineHeight = 16;
    $titleSize = 18;
    $usableHeight = $pageHeight - 2 * $margin;
    $linesPerPage = (int)floor($usableHeight / $lineHeight);

    // Split into pages
    $pages = [];
    $currentPage = [];
    $currentLine = 0;

    foreach ($textLines as $i => $line) {
        $currentPage[] = $line;
        $currentLine++;
        if ($currentLine >= $linesPerPage) {
            $pages[] = $currentPage;
            $currentPage = [];
            $currentLine = 0;
        }
    }
    if (!empty($currentPage)) {
        $pages[] = $currentPage;
    }

    // Build PDF objects
    $objects = [];
    $objCount = 0;

    // Obj 1: Catalog
    $objCount++;
    $objects[$objCount] = "<< /Type /Catalog /Pages 2 0 R >>";

    // Obj 2: Pages (placeholder, fill later)
    $objCount++;
    $pagesObj = $objCount;

    // Obj 3: Font
    $objCount++;
    $fontObj = $objCount;
    $objects[$fontObj] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

    // Obj 4: Bold Font
    $objCount++;
    $boldFontObj = $objCount;
    $objects[$boldFontObj] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

    // Create page objects
    $pageObjIds = [];
    foreach ($pages as $pageIndex => $pageLines) {
        // Content stream
        $stream = '';

        $y = $pageHeight - $margin;

        foreach ($pageLines as $li => $line) {
            $line = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $line);

            if ($pageIndex === 0 && $li === 0) {
                // Title
                $stream .= "BT /F2 {$titleSize} Tf {$margin} {$y} Td ({$line}) Tj ET\n";
            } elseif (str_starts_with($line, 'Level:') || str_starts_with($line, 'Exercise #') || str_starts_with($line, 'Description:')) {
                $stream .= "BT /F2 {$fontSize} Tf {$margin} {$y} Td ({$line}) Tj ET\n";
            } else {
                $stream .= "BT /F1 {$fontSize} Tf {$margin} {$y} Td ({$line}) Tj ET\n";
            }
            $y -= $lineHeight;
        }

        // Footer
        $footer = 'LearnAdapt - Page ' . ($pageIndex + 1) . ' of ' . count($pages);
        $footer = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $footer);
        $stream .= "BT /F1 9 Tf {$margin} 30 Td ({$footer}) Tj ET\n";

        $objCount++;
        $streamObj = $objCount;
        $objects[$streamObj] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";

        // Page object
        $objCount++;
        $pageObj = $objCount;
        $pageObjIds[] = $pageObj;
        $objects[$pageObj] = "<< /Type /Page /Parent {$pagesObj} 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Contents {$streamObj} 0 R /Resources << /Font << /F1 {$fontObj} 0 R /F2 {$boldFontObj} 0 R >> >> >>";
    }

    // Fill Pages object
    $kids = implode(' ', array_map(fn($id) => "{$id} 0 R", $pageObjIds));
    $objects[$pagesObj] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($pageObjIds) . " >>";

    // Build PDF file
    $pdf = "%PDF-1.4\n";
    $offsets = [];

    for ($i = 1; $i <= $objCount; $i++) {
        if (!isset($objects[$i])) continue;
        $offsets[$i] = strlen($pdf);
        $pdf .= "{$i} 0 obj\n{$objects[$i]}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($objCount + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $objCount; $i++) {
        $off = $offsets[$i] ?? 0;
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }

    $pdf .= "trailer\n<< /Size " . ($objCount + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

    return $pdf;
}

function getExerciseContent(int $id, string $title, string $level): string
{
    $content = match($id) {
        1 => <<<'TEXT'
EXERCISES: Introduction to Algorithms

Exercise 1: Bubble Sort
Given the array [64, 34, 25, 12, 22, 11, 90], perform bubble sort.
Show the array after each pass.

Exercise 2: Binary Search
Implement binary search on the sorted array [2, 5, 8, 12, 16, 23, 38, 56, 72, 91].
Find the element 23. Show the steps.

Exercise 3: Insertion Sort
Sort the array [12, 11, 13, 5, 6] using insertion sort.
Write the pseudocode and trace each step.

Exercise 4: Algorithm Complexity
For each algorithm below, state the time complexity (Big O):
a) Linear search in an unsorted array
b) Binary search in a sorted array
c) Bubble sort
d) Merge sort

Exercise 5: Comparison
Compare bubble sort and insertion sort:
- Best case time complexity
- Worst case time complexity
- Space complexity
- When would you choose one over the other?
TEXT,

        2 => <<<'TEXT'
EXERCISES: Object-Oriented Programming in Java

Exercise 1: Class Design
Create a class hierarchy for a university system:
- Person (name, age)
  - Student (studentId, gpa)
  - Professor (department, salary)
Write the Java classes with constructors, getters, and toString().

Exercise 2: Polymorphism
Create an interface Shape with method area().
Implement: Circle, Rectangle, Triangle.
Write a method that takes Shape[] and returns total area.

Exercise 3: Encapsulation
Design a BankAccount class with:
- Private fields: accountNumber, balance, ownerName
- Methods: deposit(), withdraw(), getBalance()
- Ensure balance cannot go negative

Exercise 4: Inheritance
Create an Animal class hierarchy:
- Animal (name, sound)
  - Dog extends Animal
  - Cat extends Animal
Override the makeSound() method in each subclass.

Exercise 5: Abstract Classes
Design an abstract class Vehicle with:
- Abstract methods: start(), stop(), fuelType()
- Concrete method: displayInfo()
Implement ElectricCar and GasCar.
TEXT,

        3 => <<<'TEXT'
EXERCISES: Advanced SQL Queries

Exercise 1: JOINs
Given tables: employees(id, name, dept_id), departments(id, name, budget)
Write queries to:
a) List all employees with their department names
b) Find departments with no employees
c) Find employees in departments with budget > 100000

Exercise 2: Subqueries
Using an orders(id, customer_id, amount, date) table:
a) Find customers who placed orders above the average amount
b) Find the top 3 customers by total spending
c) Find orders placed on the same day as the most expensive order

Exercise 3: Window Functions
Given a sales(id, employee_id, amount, sale_date) table:
a) Rank employees by total sales amount
b) Calculate running total of sales per employee
c) Find the difference between each sale and the previous one

Exercise 4: Complex Queries
Using tables: students, courses, enrollments, grades
a) Find students enrolled in all available courses
b) Calculate GPA per student (A=4, B=3, C=2, D=1, F=0)
c) Find courses where average grade is below C

Exercise 5: Performance
Explain the difference between:
a) WHERE vs HAVING
b) EXISTS vs IN
c) INNER JOIN vs LEFT JOIN
Write an optimized query for each scenario.
TEXT,

        4 => <<<'TEXT'
EXERCISES: HTML & CSS Fundamentals

Exercise 1: Semantic HTML
Create a webpage with proper semantic tags:
- Header with navigation
- Main content with article and aside
- Footer with contact info
Use at least 8 different semantic elements.

Exercise 2: Flexbox Layout
Create a responsive card layout using Flexbox:
- Cards should be 3 per row on desktop
- 2 per row on tablet
- 1 per row on mobile
Each card has: image, title, description, button.

Exercise 3: CSS Grid
Build a dashboard layout using CSS Grid:
- Sidebar (fixed 250px)
- Main content area
- Header spanning full width
- Footer spanning full width

Exercise 4: Responsive Design
Create a responsive navigation bar that:
- Shows horizontal links on desktop
- Collapses to hamburger menu on mobile
- Uses only CSS (no JavaScript)

Exercise 5: CSS Animations
Create a loading spinner using CSS:
- Circular spinning animation
- Pulsing dot animation
- Typing effect for text
Use @keyframes and transition properties.
TEXT,

        5 => <<<'TEXT'
EXERCISES: Data Structures - Trees & Graphs

Exercise 1: Binary Search Tree
Insert the following values into a BST: 50, 30, 70, 20, 40, 60, 80
a) Draw the resulting tree
b) Perform in-order traversal
c) Delete node 30 and redraw
d) Find the height of the tree

Exercise 2: AVL Tree
Insert values: 10, 20, 30, 40, 50, 25
a) Show the tree after each insertion
b) Identify which rotations are needed
c) Show the balanced tree after all insertions

Exercise 3: Graph - BFS
Given an adjacency list:
A: [B, C]
B: [A, D, E]
C: [A, F]
D: [B]
E: [B, F]
F: [C, E]
Perform BFS starting from node A. Show the visit order.

Exercise 4: Graph - DFS
Using the same graph from Exercise 3:
a) Perform DFS starting from node A
b) Identify back edges (if any)
c) Is this graph cyclic? Prove your answer.

Exercise 5: Shortest Path
Given a weighted graph, implement Dijkstra's algorithm:
A->B: 4, A->C: 2, B->D: 3, C->B: 1, C->D: 5, D->E: 1
Find shortest path from A to E.
TEXT,

        6 => <<<'TEXT'
EXERCISES: Python Basics

Exercise 1: Variables and Data Types
a) Create variables of each type: int, float, str, bool, list, dict
b) Convert between types (int to str, str to int, etc.)
c) Print the type of each variable

Exercise 2: Control Flow
Write a Python program that:
a) Checks if a number is prime
b) Prints Fizz for multiples of 3, Buzz for multiples of 5,
   FizzBuzz for both, for numbers 1 to 100
c) Finds the largest of three numbers without using max()

Exercise 3: Functions
a) Write a function that calculates factorial recursively
b) Write a function that reverses a string
c) Write a function with default parameters
d) Write a lambda function that sorts a list of tuples

Exercise 4: Lists and Dictionaries
a) Remove duplicates from a list while preserving order
b) Merge two dictionaries
c) Find the most frequent element in a list
d) Create a dictionary from two lists (keys and values)

Exercise 5: File Handling
a) Write a program that reads a text file and counts words
b) Write data to a CSV file
c) Read a JSON file and extract specific fields
d) Handle file-not-found errors gracefully
TEXT,

        7 => <<<'TEXT'
EXERCISES: REST API Design

Exercise 1: HTTP Methods
Design endpoints for a bookstore API:
- List all books
- Get a specific book
- Add a new book
- Update a book
- Delete a book
Write the URL, method, request body, and response for each.

Exercise 2: Status Codes
For each scenario, specify the correct HTTP status code:
a) Resource created successfully
b) Request body is invalid
c) User is not authenticated
d) User is authenticated but not authorized
e) Resource not found
f) Server error

Exercise 3: Authentication
Compare these authentication methods:
a) API Keys
b) JWT (JSON Web Tokens)
c) OAuth 2.0
For each, explain: how it works, pros, cons, use cases.

Exercise 4: API Versioning
Design a versioning strategy for an API:
a) URL path versioning (/v1/users)
b) Header versioning
c) Query parameter versioning
Which approach would you choose and why?

Exercise 5: Error Handling
Design a consistent error response format:
- Include: status code, message, error code, details
- Write example responses for validation errors
- Write example for rate limiting
- Document your error codes
TEXT,

        8 => <<<'TEXT'
EXERCISES: Database Normalization

Exercise 1: First Normal Form (1NF)
Normalize this table to 1NF:
StudentCourses(student_id, student_name, courses)
Row: (1, "Alice", "Math, Physics, Chemistry")
Row: (2, "Bob", "Math, Biology")

Exercise 2: Second Normal Form (2NF)
Given: OrderDetails(order_id, product_id, product_name,
       quantity, product_price)
Identify partial dependencies and convert to 2NF.

Exercise 3: Third Normal Form (3NF)
Given: Employee(emp_id, emp_name, dept_id, dept_name,
       dept_manager)
Identify transitive dependencies and convert to 3NF.

Exercise 4: Full Normalization
Starting table:
Invoice(inv_id, date, cust_id, cust_name, cust_address,
        item_id, item_name, item_price, quantity, total)
Normalize step by step from UNF to 3NF.
Show each intermediate step.

Exercise 5: Denormalization
Given a fully normalized e-commerce database:
- customers, orders, order_items, products, categories
When and why would you denormalize?
Give 3 specific examples with justification.
Discuss the trade-offs of each decision.
TEXT,

        default => <<<TEXT
EXERCISES: {$title}

Level: {$level}

Practice exercises for this topic will help you
build a strong understanding of the core concepts.

Exercise 1: Review the key concepts covered in class.
Exercise 2: Solve the practice problems provided.
Exercise 3: Implement the algorithms discussed.
Exercise 4: Analyze the time and space complexity.
Exercise 5: Compare different approaches and solutions.
TEXT,
    };

    return $content;
}
