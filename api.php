<?php
header("Content-Type: application/json"); // Set response format
header("Access-Control-Allow-Origin: *"); // Allow all origins (CORS)
header("Access-Control-Allow-Methods: POST"); // Allow only POST method for this API
header("Access-Control-Allow-Headers: Content-Type"); // Allow headers

$mysqli = new mysqli("localhost", "root", "", "internet");

if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to connect to MySQL: " . $mysqli->connect_error]);
    exit();
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case "getNewsFromCategory":
        if (!isset($_GET["category_id"])) {
            echo json_encode(["error" => "Category ID is required."]);
            exit();
        }
    
        $category_id = intval($_GET["category_id"]);
    
        $stmt = $mysqli->prepare("SELECT * FROM news WHERE category = ?");
        
        if (!$stmt) {
            error_log("Prepare failed: " . $mysqli->error);
            echo json_encode(["error" => "SQL error: " . $mysqli->error]);
            exit();
        }
    
        $stmt->bind_param("i", $category_id);
        $stmt->execute();
        
        $result = $stmt->get_result();
        $news = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode($news);
        $stmt->close();
        break;
    
    case "insertCategory":
        // Insert category
        $data = json_decode(file_get_contents("php://input"), true);
        if (!empty($data["title"])) {
            $stmt = $mysqli->prepare("INSERT INTO category (title) VALUES (?)");
            $stmt->bind_param("s", $data["title"]);
            $stmt->execute();
            echo json_encode(["message" => "Category created successfully!", "id" => $stmt->insert_id]);
            $stmt->close();
        } else {
            http_response_code(400);
            echo json_encode(["error" => "Category title is required."]);
        }
        break;
    case "insertNews":
        // Get request body data
        $data = json_decode(file_get_contents("php://input"), true);
    
        if (!empty($data["title"]) && !empty($data["body"]) && isset($data["category"])) {
            // Fetch category ID by category title
            $categoryTitle = $data["category"];
            $categoryQuery = $mysqli->prepare("SELECT category_id FROM category WHERE title = ?");
            
            if (!$categoryQuery) {
                error_log("Prepare failed: " . $mysqli->error);
                echo json_encode(["error" => "SQL error: " . $mysqli->error]);
                exit();
            }
    
            $categoryQuery->bind_param("s", $categoryTitle);
            
            if (!$categoryQuery->execute()) {
                error_log("Execute failed: " . $categoryQuery->error);
                echo json_encode(["error" => "Execution error: " . $categoryQuery->error]);
                exit();
            }
    
            $categoryQuery->store_result();
            $categoryQuery->bind_result($categoryId);
            $categoryQuery->fetch();
    
            // Check if category exists
            if (!$categoryId) {
                echo json_encode(["error" => "Category not found"]);
                exit();
            }
    
            // Insert news with category ID
            $stmt = $mysqli->prepare("INSERT INTO news (title, body, category) VALUES (?, ?, ?)");
    
            if (!$stmt) {
                error_log("Prepare failed: " . $mysqli->error);
                echo json_encode(["error" => "SQL error: " . $mysqli->error]);
                exit();
            }
    
            // Insert the category ID instead of the title
            $stmt->bind_param("ssi", $data["title"], $data["body"], $categoryId);
    
            if (!$stmt->execute()) {
                error_log("Execute failed: " . $stmt->error);
                echo json_encode(["error" => "Execution error: " . $stmt->error]);
                exit();
            }
    
            echo json_encode(["message" => "News created successfully!", "id" => $stmt->insert_id]);
            $stmt->close();
            $categoryQuery->close();
        } else {
            http_response_code(400);
            echo json_encode(["error" => "Title, body, and category title are required."]);
        }
        break;
        
        

    case "edit":
        // Edit news
        $data = json_decode(file_get_contents("php://input"), true);
        if (!empty($data["id"]) && !empty($data["title"]) && !empty($data["body"])) {
            $stmt = $mysqli->prepare("UPDATE news SET title = ?, body = ? WHERE id = ?");
            $stmt->bind_param("ssi", $data["title"], $data["body"], $data["id"]);
            $stmt->execute();
            echo json_encode(["message" => "News updated successfully!"]);
            $stmt->close();
        } else {
            http_response_code(400);
            echo json_encode(["error" => "ID, title, and content are required."]);
        }
        break;

    case "delete":
        // Delete news
        $id = $_GET['id'] ?? null;
        if ($id) {
            $stmt = $mysqli->prepare("DELETE FROM news WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            echo json_encode(["message" => "News deleted successfully!"]);
            $stmt->close();
        } else {
            http_response_code(400);
            echo json_encode(["error" => "ID is required."]);
        }
        break;

        case "getNews":
            if (isset($_GET["id"])) {
                // Get a single news item with category name
                $id = (int)$_GET["id"];
                $stmt = $mysqli->prepare("
                    SELECT n.id, n.title, n.image, n.body, c.title AS category_name 
                    FROM news n
                    LEFT JOIN category c ON n.category = c.category_id
                    WHERE n.id = ?
                ");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                echo json_encode($result->fetch_assoc());
                $stmt->close();
            } else {
                // Get all news with category names
                $query = "
                    SELECT n.id, n.title, n.image, n.body, c.title AS category_name 
                    FROM news n
                    LEFT JOIN category c ON n.category = c.category_id
                ";
        
                $result = $mysqli->query($query);
                echo json_encode($result->fetch_all(MYSQLI_ASSOC));
            }
            break;
        

        case "getCategoriesWithNews":
            $query = "
                SELECT 
                    c.category_id, 
                    c.title AS category_title, 
                    n.id AS news_id, 
                    n.title AS news_title, 
                    n.image, 
                    n.body
                FROM category c
                LEFT JOIN news n ON c.category_id = n.category
                ORDER BY c.category_id, n.id DESC
            ";
        
            $result = $mysqli->query($query);
        
            if (!$result) {
                error_log("Query failed: " . $mysqli->error);
                echo json_encode(["error" => "SQL error: " . $mysqli->error]);
                exit();
            }
        
            $categories = [];
            
            while ($row = $result->fetch_assoc()) {
                $category_id = $row["category_id"];
                
                if (!isset($categories[$category_id])) {
                    $categories[$category_id] = [
                        "category_id" => $category_id,
                        "category_title" => $row["category_title"],
                        "news" => []
                    ];
                }
        
                if ($row["news_id"]) {
                    $categories[$category_id]["news"][] = [
                        "id" => $row["news_id"],
                        "title" => $row["news_title"],
                        "image" => $row["image"],
                        "body" => $row["body"]
                    ];
                }
            }
        
            echo json_encode(array_values($categories));
            break;

        case "signup":
            // Get input data from the request
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!empty($data["name"]) && !empty($data["email"]) && !empty($data["password"])) {
                // Check if email already exists
                $stmt = $mysqli->prepare("SELECT id FROM admin WHERE email = ?");
                $stmt->bind_param("s", $data["email"]);
                $stmt->execute();
                $stmt->store_result();
                
                if ($stmt->num_rows > 0) {
                    echo json_encode(["error" => "Email is already taken."]);
                    $stmt->close();
                    exit();
                }
        
                // Hash password
                $hashedPassword = password_hash($data["password"], PASSWORD_BCRYPT);
        
                // Insert the new admin
                $stmt = $mysqli->prepare("INSERT INTO admin (name, email, password) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $data["name"], $data["email"], $hashedPassword);
                $stmt->execute();
        
                echo json_encode(["message" => "Signup successful!"]);
                $stmt->close();
            } else {
                echo json_encode(["error" => "All fields are required."]);
            }
            break;
            
        case "login":
            // Get login credentials from the request
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!empty($data["email"]) && !empty($data["password"])) {
                // Check if the email exists
                $stmt = $mysqli->prepare("SELECT id, name, password FROM admin WHERE email = ?");
                $stmt->bind_param("s", $data["email"]);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $admin = $result->fetch_assoc();
                    
                    // Verify the password
                    if (password_verify($data["password"], $admin["password"])) {
                        // Success, return admin details (exclude password)
                        unset($admin["password"]);
                        echo json_encode(["message" => "Login successful", "admin" => $admin]);
                    } else {
                        echo json_encode(["error" => "Incorrect password."]);
                    }
                } else {
                    echo json_encode(["error" => "No admin found with that email."]);
                }
                
                $stmt->close();
            } else {
                echo json_encode(["error" => "Email and password are required."]);
            }
            break;

        case "getAdminInfo":
            // Assuming the admin's ID is stored in a session or passed in the request
            if (isset($_GET["admin_id"])) {
                $admin_id = (int)$_GET["admin_id"];
                
                // Fetch admin details
                $stmt = $mysqli->prepare("SELECT id, name, email FROM admin WHERE id = ?");
                $stmt->bind_param("i", $admin_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $admin = $result->fetch_assoc();
                    echo json_encode($admin);
                } else {
                    echo json_encode(["error" => "Admin not found."]);
                }
                
                $stmt->close();
            } else {
                echo json_encode(["error" => "Admin ID is required."]);
            }
            break;
            
        case "getCategories":
            // Fetch all categories
            $result = $mysqli->query("SELECT * FROM category");
        
            if ($result->num_rows > 0) {
                // Fetch all categories as an associative array
                $categories = $result->fetch_all(MYSQLI_ASSOC);
        
                // Return the categories in JSON format
                echo json_encode(["categories" => $categories]);
            } else {
                // If no categories exist
                echo json_encode(["error" => "No categories found."]);
            }
            break;
            

    default:
        http_response_code(400);
        echo json_encode(["error" => "Invalid action specified."]);
        break;
}

$mysqli->close();
?>
