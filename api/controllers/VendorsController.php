<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Auth.php';
require_once __DIR__ . '/../utils/PermissionHelper.php';

class VendorsController {
    private $db;
    private $conn;
    
    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }
    
    // GET /api/vendors - Get all vendors
    public function index() {
        try {
            $userId = Auth::getUserId();
            if (!$userId) {
                Response::unauthorized('Authentication required');
                return;
            }

            $currentUser = Auth::getCurrentUser($this->conn);
            if (!PermissionHelper::hasPermission($currentUser['permissions'], 'vendors', 'read')) {
                Response::forbidden('You do not have permission to view vendors');
                return;
            }

            $sql = "SELECT * FROM vendors ORDER BY created_at DESC";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            Response::success($vendors, 'Vendors retrieved successfully');
        } catch (PDOException $e) {
            error_log("Get vendors error: " . $e->getMessage());
            Response::error('Failed to fetch vendors: ' . $e->getMessage());
        }
    }
    
    // GET /api/vendors/{id} - Get single vendor with licenses
    public function show($id) {
        try {
            // If ID is a vendor name, find by name instead
            $sql = "SELECT * FROM vendors WHERE id = ? OR name = ? LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$id, $id]);
            $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$vendor) {
                Response::notFound('Vendor not found');
            }
            
            // Add display fields to vendor
            $vendor['display_name'] = $vendor['name'];
            
            // Fetch vendor's licenses with currency info (purchases from this vendor)
            $sql = "SELECT 
                lp.*,
                c.symbol as currency_symbol
            FROM license_purchases lp
            LEFT JOIN currencies c ON lp.currency_code = c.code
            WHERE lp.vendor = ?
            ORDER BY lp.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$vendor['name']]);
            $licensesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Transform licenses and calculate stats
            $licenses = [];
            $activeLicenses = 0;
            $expiredLicenses = 0;
            $totalCostInr = 0;
            $now = new DateTime();
            
            foreach ($licensesRaw as $l) {
                $expirationDate = !empty($l['expiration_date']) ? new DateTime($l['expiration_date']) : null;
                $isExpired = $expirationDate ? $expirationDate <= $now : false;
                
                // Calculate total cost in INR (purchase cost for vendor view)
                $originalTotal = floatval($l['total_cost'] ?? 0);
                $totalCostInrLicense = floatval($l['total_cost_inr'] ?? $originalTotal);
                
                $quantityValue = max(1, intval($l['purchased_quantity'] ?? $l['quantity'] ?? 1));
                
                $license = [
                    'id' => $l['id'],
                    'tool_name' => $l['tool_name'] ?? 'N/A',
                    'tool_description' => $l['model'] ?? $l['version'] ?? null,
                    'tool_vendor' => $l['vendor'] ?? 'N/A',
                    'purchase_date' => $l['purchase_date'],
                    'expiry_date' => $l['expiration_date'],
                    'number_of_users' => $quantityValue,
                    'cost_per_user' => floatval($l['cost_per_user'] ?? 0),
                    'total_cost' => $originalTotal,
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
            
            // Return vendor details with licenses and stats
            $data = [
                'vendor' => $vendor,
                'licenses' => $licenses,
                'stats' => [
                    'total_licenses' => count($licenses),
                    'active_licenses' => $activeLicenses,
                    'expired_licenses' => $expiredLicenses,
                    'total_cost' => $totalCostInr
                ]
            ];
            
            Response::success($data, 'Vendor retrieved successfully');
        } catch (PDOException $e) {
            error_log("Get vendor error: " . $e->getMessage());
            Response::error('Failed to fetch vendor: ' . $e->getMessage());
        }
    }
    
    // POST /api/vendors - Create new vendor
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
            $mode_of_payment = $normalizeOptional($input['mode_of_payment'] ?? null);
            $amount = $input['amount'] ?? null;
            $quantity = $input['quantity'] ?? null;
            
            if (empty($name)) {
                Response::badRequest('Vendor name is required');
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
                
                $uploadDir = __DIR__ . '/../../public/uploads/vendors/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                // Use the validated lowercase extension
                $fileName = 'vendor_doc_' . uniqid() . '_' . time() . '.' . $fileExtension;
                $uploadPath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['document']['tmp_name'], $uploadPath)) {
                    $documentPath = '/uploads/vendors/' . $fileName;
                    error_log("File uploaded successfully: $documentPath");
                } else {
                    error_log("Failed to move uploaded file");
                    Response::error('Failed to save uploaded file');
                }
            }
            
            $vendorId = $this->generateUUID();
            
            // Build SQL based on whether document was uploaded
            if ($documentPath) {
                $sql = "INSERT INTO vendors (
                    id, name, contact_person, email, phone, address, company_name,
                    gst_treatment, source_of_supply, gst, currency_id, 
                    mode_of_payment, amount, quantity, document_path
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([
                    $vendorId, $name, $contact_person, $email, $phone, $address, $company_name,
                    $gst_treatment, $source_of_supply, $gst, $currency_id,
                    $mode_of_payment, $amount, $quantity, $documentPath
                ]);
            } else {
                $sql = "INSERT INTO vendors (
                    id, name, contact_person, email, phone, address, company_name,
                    gst_treatment, source_of_supply, gst, currency_id, 
                    mode_of_payment, amount, quantity
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([
                    $vendorId, $name, $contact_person, $email, $phone, $address, $company_name,
                    $gst_treatment, $source_of_supply, $gst, $currency_id,
                    $mode_of_payment, $amount, $quantity
                ]);
            }
            
            // Fetch the created vendor
            $stmt = $this->conn->prepare("SELECT * FROM vendors WHERE id = ?");
            $stmt->execute([$vendorId]);
            $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            Response::success($vendor, 'Vendor created successfully', 201);
        } catch (PDOException $e) {
            error_log("Create vendor error: " . $e->getMessage());
            Response::error('Failed to create vendor: ' . $e->getMessage());
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
    
    // PUT /api/vendors/{id} - Update vendor
    public function update($id) {
        try {
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);
            
            if (!$input) {
                Response::badRequest('Invalid JSON input');
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
            
            $sql = "UPDATE vendors SET 
                name = ?,
                contact_person = ?,
                email = ?,
                phone = ?,
                address = ?,
                company_name = ?,
                gst_treatment = ?,
                source_of_supply = ?,
                gst = ?,
                currency_id = ?,
                mode_of_payment = ?,
                amount = ?,
                quantity = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $input['name'] ?? '',
                $normalizeOptional($input['contact_person'] ?? null),
                $normalizeOptional($input['email'] ?? null),
                $normalizeOptional($input['phone'] ?? null),
                $normalizeOptional($input['address'] ?? null),
                $normalizeOptional($input['company_name'] ?? null),
                $normalizeOptional($input['gst_treatment'] ?? null),
                $normalizeOptional($input['source_of_supply'] ?? null),
                $normalizeOptional($input['gst'] ?? null),
                $normalizeOptional($input['currency_id'] ?? null),
                $normalizeOptional($input['mode_of_payment'] ?? null),
                $input['amount'] ?? null,
                $input['quantity'] ?? null,
                $id
            ]);
            
            // Fetch the updated vendor
            $stmt = $this->conn->prepare("SELECT * FROM vendors WHERE id = ?");
            $stmt->execute([$id]);
            $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$vendor) {
                Response::notFound('Vendor not found');
            }
            
            Response::success($vendor, 'Vendor updated successfully');
        } catch (PDOException $e) {
            error_log("Update vendor error: " . $e->getMessage());
            Response::error('Failed to update vendor: ' . $e->getMessage());
        }
    }
    
    // DELETE /api/vendors/{id} - Delete vendor
    public function destroy($id) {
        try {
            $sql = "DELETE FROM vendors WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                Response::notFound('Vendor not found');
            }
            
            Response::success(null, 'Vendor deleted successfully');
        } catch (PDOException $e) {
            error_log("Delete vendor error: " . $e->getMessage());
            Response::error('Failed to delete vendor: ' . $e->getMessage());
        }
    }
}
