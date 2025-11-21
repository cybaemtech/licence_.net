<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Auth.php';
require_once __DIR__ . '/../utils/PermissionHelper.php';

class ClientController {
    private $db;
    private $conn;
    
    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }
    
    public function index() {
        try {
            $userId = Auth::getUserId();
            if (!$userId) {
                Response::unauthorized('Authentication required');
                return;
            }

            $currentUser = Auth::getCurrentUser($this->conn);
            if (!PermissionHelper::hasPermission($currentUser['permissions'], 'clients', 'read')) {
                Response::forbidden('You do not have permission to view clients');
                return;
            }

            // Fetch clients with currency information
            $sql = "SELECT 
                        c.*,
                        cur.code as currency_code,
                        cur.name as currency_name,
                        cur.symbol as currency_symbol
                    FROM clients c
                    LEFT JOIN currencies cur ON c.currency_id = cur.id
                    ORDER BY c.created_at DESC";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add aliases for frontend compatibility
            foreach ($clients as &$client) {
                if (isset($client['company_name'])) {
                    $client['company'] = $client['company_name'];
                }
                if (isset($client['source_of_supply'])) {
                    $client['place_of_supply'] = $client['source_of_supply'];
                }
            }
            
            Response::success($clients, 'Clients retrieved successfully');
        } catch (PDOException $e) {
            error_log("Get clients error: " . $e->getMessage());
            Response::error('Failed to fetch clients: ' . $e->getMessage());
        }
    }
    
    public function show($id) {
        try {
            $sql = "SELECT * FROM clients WHERE id = ? LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$client) {
                Response::notFound('Client not found');
            }
            
            // Add currency and display fields to client
            $client['currency_name'] = 'Indian Rupee';
            $client['currency_symbol'] = '₹';
            $client['currency_code'] = 'INR';
            $client['display_name'] = $client['name'];
            
            // Fetch client's licenses with currency info
            // Get licenses either directly assigned to client OR sold to this client through sales
            // For sold licenses, use selling price instead of purchase price
            $sql = "SELECT DISTINCT
                lp.*,
                c.symbol as currency_symbol,
                s.total_selling_price as selling_price,
                s.sale_date,
                s.expiry_date as sale_expiry_date
            FROM license_purchases lp
            LEFT JOIN currencies c ON lp.currency_code = c.code
            LEFT JOIN sales s ON lp.id = s.purchase_id AND s.client_id = ?
            WHERE lp.client_id = ? OR s.client_id = ?
            ORDER BY lp.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$id, $id, $id]);
            $licensesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Transform licenses and calculate stats
            $licenses = [];
            $activeLicenses = 0;
            $expiredLicenses = 0;
            $totalCostInr = 0;
            $now = new DateTime();
            
            foreach ($licensesRaw as $l) {
                // Use sale expiry date if available, otherwise use license expiration date
                $expiryDateStr = !empty($l['sale_expiry_date']) ? $l['sale_expiry_date'] : $l['expiration_date'];
                $expirationDate = !empty($expiryDateStr) ? new DateTime($expiryDateStr) : null;
                $isExpired = $expirationDate ? $expirationDate <= $now : false;
                
                // If this license was sold to this client, use selling price
                // Otherwise use purchase price
                $isSold = !empty($l['selling_price']);
                
                if ($isSold) {
                    // Use selling price for sold licenses
                    $totalCostInrLicense = floatval($l['selling_price']);
                    $purchaseDate = $l['sale_date'] ?? $l['purchase_date'];
                } else {
                    // Use purchase price for directly assigned licenses
                    $originalTotal = floatval($l['total_cost'] ?? 0);
                    $totalCostInrLicense = floatval($l['total_cost_inr'] ?? $originalTotal);
                    $purchaseDate = $l['purchase_date'];
                }
                
                $quantityValue = intval($l['purchased_quantity'] ?? $l['quantity'] ?? 1);
                
                $license = [
                    'id' => $l['id'],
                    'tool_name' => $l['tool_name'] ?? 'N/A',
                    'tool_description' => $l['model'] ?? $l['version'] ?? null,
                    'tool_vendor' => $l['vendor'] ?? 'N/A',
                    'purchase_date' => $purchaseDate,
                    'expiry_date' => $expiryDateStr,
                    'number_of_users' => $quantityValue,
                    'cost_per_user' => $isSold ? ($totalCostInrLicense / max(1, $quantityValue)) : floatval($l['cost_per_user'] ?? 0),
                    'total_cost' => $totalCostInrLicense,
                    'total_cost_inr' => $totalCostInrLicense,
                    'currency_code' => $l['currency_code'] ?? 'INR',
                    'currency_symbol' => $l['currency_symbol'] ?? '₹',
                    'status' => $isExpired ? 'expired' : 'active'
                ];
                
                $licenses[] = $license;
                
                if ($isExpired) {
                    $expiredLicenses++;
                } else {
                    $activeLicenses++;
                }
                
                $totalCostInr += $totalCostInrLicense;
            }
            
            // Return client details with licenses and stats
            $data = [
                'client' => $client,
                'licenses' => $licenses,
                'stats' => [
                    'total_licenses' => count($licenses),
                    'active_licenses' => $activeLicenses,
                    'expired_licenses' => $expiredLicenses,
                    'total_cost' => $totalCostInr
                ]
            ];
            
            Response::success($data, 'Client retrieved successfully');
        } catch (PDOException $e) {
            error_log("Get client error: " . $e->getMessage());
            Response::error('Failed to fetch client: ' . $e->getMessage());
        }
    }
    
    public function store() {
        try {
            // Check if this is a multipart/form-data request (file upload)
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $isFormData = stripos($contentType, 'multipart/form-data') !== false;
            
            if ($isFormData) {
                // Handle FormData (file upload case)
                error_log("Processing FormData request with file upload");
                $input = $_POST;
                error_log("POST data: " . print_r($input, true));
                error_log("FILES data: " . print_r($_FILES, true));
            } else {
                // Handle JSON request (no file upload)
                $rawInput = file_get_contents('php://input');
                $input = json_decode($rawInput, true);
                
                if (!$input) {
                    Response::badRequest('Invalid JSON input');
                }
            }
            
            // Helper to normalize optional fields - trim and convert empty to NULL
            $normalizeOptional = function($value) {
                if (!isset($value)) return null;
                if (is_string($value)) {
                    $trimmed = trim($value);
                    return $trimmed === '' ? null : $trimmed;
                }
                return $value;
            };
            
            $name = trim($input['name'] ?? '');
            $contact_person = $normalizeOptional($input['contact_person'] ?? null);
            $email = $normalizeOptional($input['email'] ?? null);
            $phone = $normalizeOptional($input['phone'] ?? null);
            $address = $normalizeOptional($input['address'] ?? null);
            $company_name = $normalizeOptional($input['company_name'] ?? null);
            $gst_treatment = $normalizeOptional($input['gst_treatment'] ?? null);
            $source_of_supply = $normalizeOptional($input['source_of_supply'] ?? null);
            $gst = $normalizeOptional($input['gst'] ?? null);
            $currency_id = $normalizeOptional($input['currency_id'] ?? null);
            
            if (empty($name)) {
                Response::badRequest('Client name is required');
            }
            
            $userStmt = $this->conn->query("SELECT id FROM users LIMIT 1");
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            $userId = $user ? $user['id'] : null;
            
            if (!$userId) {
                Response::error('No user found in database. Please create a user first.');
            }
            
            // Handle file upload if present
            $documentPath = null;
            if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
                // Validate file type - only allow safe document formats
                $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
                $allowedMimeTypes = [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'image/jpeg',
                    'image/png'
                ];
                
                $originalFilename = $_FILES['document']['name'];
                $fileExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
                
                // Reject files with multiple extensions (e.g., file.php.jpg)
                $filenameParts = explode('.', $originalFilename);
                if (count($filenameParts) > 2) {
                    error_log("File upload rejected: multiple extensions detected in $originalFilename");
                    Response::badRequest('Files with multiple extensions are not allowed');
                }
                
                // Use finfo for more robust MIME detection
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $fileMimeType = finfo_file($finfo, $_FILES['document']['tmp_name']);
                finfo_close($finfo);
                
                if (!in_array($fileExtension, $allowedExtensions)) {
                    error_log("File upload rejected: invalid extension $fileExtension");
                    Response::badRequest('Invalid file type. Allowed types: ' . implode(', ', $allowedExtensions));
                }
                
                if (!in_array($fileMimeType, $allowedMimeTypes)) {
                    error_log("File upload rejected: invalid MIME type $fileMimeType");
                    Response::badRequest('Invalid file content type');
                }
                
                $uploadDir = __DIR__ . '/../../public/uploads/clients/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileName = 'client_doc_' . uniqid() . '_' . time() . '.' . $fileExtension;
                $uploadPath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['document']['tmp_name'], $uploadPath)) {
                    $documentPath = '/uploads/clients/' . $fileName;
                    error_log("File uploaded successfully: $documentPath");
                } else {
                    error_log("Failed to move uploaded file");
                    Response::error('Failed to save uploaded file');
                }
            }
            
            $clientId = $this->generateUUID();
            
            $sql = "INSERT INTO clients (
                id, user_id, name, contact_person, email, phone, address, company_name,
                gst_treatment, source_of_supply, gst, currency_id, document_path, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $clientId,
                $userId,
                $name,
                $contact_person,
                $email,
                $phone,
                $address,
                $company_name,
                $gst_treatment,
                $source_of_supply,
                $gst,
                $currency_id,
                $documentPath,
                'active'
            ]);
            
            $stmt = $this->conn->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            Response::success($client, 'Client created successfully', 201);
        } catch (PDOException $e) {
            error_log("Create client error: " . $e->getMessage());
            Response::error('Failed to create client: ' . $e->getMessage());
        }
    }
    
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    public function update($id) {
        try {
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);
            
            if (!$input) {
                Response::badRequest('Invalid JSON input');
            }
            
            // Build update query dynamically to preserve existing values when fields are not provided
            $updates = [];
            $params = [];
            
            if (isset($input['name'])) {
                $updates[] = "name = ?";
                $params[] = $input['name'];
            }
            if (isset($input['contact_person'])) {
                $updates[] = "contact_person = ?";
                $params[] = $input['contact_person'];
            }
            if (isset($input['email'])) {
                $updates[] = "email = ?";
                $params[] = $input['email'];
            }
            if (isset($input['phone'])) {
                $updates[] = "phone = ?";
                $params[] = $input['phone'];
            }
            if (isset($input['address'])) {
                $updates[] = "address = ?";
                $params[] = $input['address'];
            }
            if (isset($input['company_name'])) {
                $updates[] = "company_name = ?";
                $params[] = $input['company_name'];
            }
            if (isset($input['gst_treatment'])) {
                $updates[] = "gst_treatment = ?";
                $params[] = $input['gst_treatment'];
            }
            if (isset($input['source_of_supply'])) {
                $updates[] = "source_of_supply = ?";
                $params[] = $input['source_of_supply'];
            }
            if (isset($input['gst'])) {
                $updates[] = "gst = ?";
                $params[] = $input['gst'];
            }
            if (isset($input['currency_id'])) {
                $updates[] = "currency_id = ?";
                $params[] = $input['currency_id'];
            }
            if (isset($input['document_path'])) {
                $updates[] = "document_path = ?";
                $params[] = $input['document_path'];
            }
            
            // Always update the updated_at timestamp
            $updates[] = "updated_at = CURRENT_TIMESTAMP";
            
            if (empty($updates)) {
                Response::badRequest('No fields to update');
            }
            
            $sql = "UPDATE clients SET " . implode(', ', $updates) . " WHERE id = ?";
            $params[] = $id;
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            $stmt = $this->conn->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$client) {
                Response::notFound('Client not found');
            }
            
            Response::success($client, 'Client updated successfully');
        } catch (PDOException $e) {
            error_log("Update client error: " . $e->getMessage());
            Response::error('Failed to update client: ' . $e->getMessage());
        }
    }
    
    public function destroy($id) {
        try {
            $sql = "DELETE FROM clients WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                Response::notFound('Client not found');
            }
            
            Response::success(null, 'Client deleted successfully');
        } catch (PDOException $e) {
            error_log("Delete client error: " . $e->getMessage());
            Response::error('Failed to delete client: ' . $e->getMessage());
        }
    }
}
