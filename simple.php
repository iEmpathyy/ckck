<?php
session_start();

// ==================== KONFIGURASI ====================
$DB_HOST = 'localhost';
$DB_NAME = 'myhub';
$DB_USER = 'root';
$DB_PASS = 'empathy';

$ADMIN_PASSWORD = 'jz1v'; // GANTI PASSWORD INI!

// ==================== KONEKSI DATABASE ====================
function connectDB() {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    try {
        $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", 
                      $DB_USER, $DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die("❌ Database Error: " . $e->getMessage());
    }
}

// ==================== CREATE TABLE IF NOT EXISTS ====================
function createTableIfNotExists($pdo) {
    $sql = "CREATE TABLE IF NOT EXISTS licenses (
        id INT PRIMARY KEY AUTO_INCREMENT,
        license_key VARCHAR(100) UNIQUE NOT NULL,
        hwid VARCHAR(255) DEFAULT NULL,
        expires DATETIME NOT NULL,
        tier VARCHAR(50) DEFAULT 'basic',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
}

// ==================== LOGIN CHECK ====================
function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ?page=login');
        exit();
    }
}

// ==================== LOGIN HANDLER ====================
if (isset($_POST['login'])) {
    if ($_POST['password'] === $ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: ?');
        exit();
    } else {
        $login_error = "Password salah!";
    }
}

// ==================== LOGOUT ====================
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ?page=login');
    exit();
}

// ==================== ACTION HANDLERS ====================
$pdo = connectDB();
createTableIfNotExists($pdo);

$message = '';
$message_type = '';

// ADD LICENSE
if (isset($_POST['add_license'])) {
    $license_key = trim($_POST['license_key']);
    $tier = $_POST['tier'];
    $duration = $_POST['duration'];
    
    // Calculate expiration
    $expires = date('Y-m-d H:i:s', strtotime("+$duration days"));
    
    $stmt = $pdo->prepare("INSERT INTO licenses (license_key, tier, expires) VALUES (?, ?, ?)");
    if ($stmt->execute([$license_key, $tier, $expires])) {
        $message = "✅ License added: $license_key";
        $message_type = 'success';
    } else {
        $message = "❌ Failed to add license";
        $message_type = 'error';
    }
}

// ADD HWID MANUALLY
if (isset($_POST['add_hwid'])) {
    $license_id = $_POST['license_id'];
    $hwid = trim($_POST['hwid']);
    
    $stmt = $pdo->prepare("UPDATE licenses SET hwid = ? WHERE id = ?");
    if ($stmt->execute([$hwid, $license_id])) {
        $message = "✅ HWID added to license";
        $message_type = 'success';
    } else {
        $message = "❌ Failed to add HWID";
        $message_type = 'error';
    }
}

// DELETE HWID (UNBAN/WHITELIST RESET)
if (isset($_GET['delete_hwid'])) {
    $license_id = $_GET['delete_hwid'];
    
    $stmt = $pdo->prepare("UPDATE licenses SET hwid = NULL WHERE id = ?");
    if ($stmt->execute([$license_id])) {
        $message = "✅ HWID removed (user can login on new device)";
        $message_type = 'success';
    }
}

// DELETE LICENSE
if (isset($_GET['delete_license'])) {
    $license_id = $_GET['delete_license'];
    
    $stmt = $pdo->prepare("DELETE FROM licenses WHERE id = ?");
    if ($stmt->execute([$license_id])) {
        $message = "✅ License deleted";
        $message_type = 'success';
    }
}

// BULK DELETE EXPIRED
if (isset($_GET['bulk_delete_expired'])) {
    $stmt = $pdo->prepare("DELETE FROM licenses WHERE expires < NOW()");
    $deleted = $stmt->execute();
    $message = "✅ Deleted all expired licenses";
    $message_type = 'success';
}

// ==================== GET ALL LICENSES ====================
$licenses = [];
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? 'all';

$sql = "SELECT * FROM licenses WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (license_key LIKE ? OR hwid LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter === 'active') {
    $sql .= " AND expires > NOW()";
} elseif ($filter === 'expired') {
    $sql .= " AND expires < NOW()";
} elseif ($filter === 'with_hwid') {
    $sql .= " AND hwid IS NOT NULL";
} elseif ($filter === 'without_hwid') {
    $sql .= " AND hwid IS NULL";
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==================== STATISTICS ====================
$stats = [
    'total' => 0,
    'active' => 0,
    'expired' => 0,
    'with_hwid' => 0,
    'without_hwid' => 0
];

foreach ($licenses as $license) {
    $stats['total']++;
    
    if (strtotime($license['expires']) > time()) {
        $stats['active']++;
    } else {
        $stats['expired']++;
    }
    
    if ($license['hwid']) {
        $stats['with_hwid']++;
    } else {
        $stats['without_hwid']++;
    }
}

// ==================== PAGE ROUTING ====================
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// ==================== HTML OUTPUT ====================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HWID Admin - Simple</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: Arial, sans-serif; }
        body { background: #f0f2f5; padding: 20px; }
        
        /* HEADER */
        .header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; 
            padding: 20px; 
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 24px; }
        .logout-btn { 
            background: rgba(255,255,255,0.2); 
            color: white; 
            border: none; 
            padding: 8px 15px; 
            border-radius: 5px; 
            cursor: pointer;
            text-decoration: none;
        }
        
        /* MESSAGES */
        .message { 
            padding: 15px; 
            margin-bottom: 20px; 
            border-radius: 5px; 
        }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        /* STATS */
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 15px; 
            margin-bottom: 20px; 
        }
        .stat-card { 
            background: white; 
            padding: 20px; 
            border-radius: 10px; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-number { font-size: 32px; font-weight: bold; margin: 10px 0; }
        .total { color: #3498db; }
        .active { color: #2ecc71; }
        .expired { color: #e74c3c; }
        .hwid { color: #9b59b6; }
        
        /* CONTROLS */
        .controls { 
            background: white; 
            padding: 20px; 
            border-radius: 10px; 
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .search-box { flex: 1; min-width: 300px; }
        .search-box input { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
        }
        .filter-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .filter-btn { 
            padding: 8px 15px; 
            border: 1px solid #ddd; 
            background: #f8f9fa; 
            border-radius: 5px;
            cursor: pointer;
        }
        .filter-btn.active { background: #3498db; color: white; border-color: #3498db; }
        
        /* ACTIONS */
        .actions { margin-bottom: 20px; }
        .action-btn { 
            padding: 10px 15px; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        .btn-primary { background: #3498db; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-success { background: #2ecc71; color: white; }
        
        /* TABLE */
        .table-container { 
            background: white; 
            border-radius: 10px; 
            overflow: hidden;
            margin-bottom: 20px;
        }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; padding: 15px; text-align: left; border-bottom: 2px solid #ddd; }
        td { padding: 12px 15px; border-bottom: 1px solid #eee; }
        tr:hover { background: #f9f9f9; }
        
        /* BADGES */
        .badge { 
            padding: 3px 8px; 
            border-radius: 3px; 
            font-size: 12px; 
            font-weight: bold; 
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        
        /* FORMS */
        .form-container { 
            background: white; 
            padding: 20px; 
            border-radius: 10px; 
            margin-bottom: 20px;
            max-width: 500px;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
        }
        .form-actions { display: flex; gap: 10px; margin-top: 20px; }
        
        /* LOGIN PAGE */
        .login-container { 
            max-width: 400px; 
            margin: 50px auto; 
            background: white; 
            padding: 40px; 
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .login-container h2 { text-align: center; margin-bottom: 30px; }
        .login-error { 
            background: #f8d7da; 
            color: #721c24; 
            padding: 10px; 
            border-radius: 5px; 
            margin-bottom: 20px;
        }
        
        /* UTILITIES */
        .text-center { text-align: center; }
        .text-muted { color: #777; }
        .text-small { font-size: 12px; }
        .mt-20 { margin-top: 20px; }
        .mb-20 { margin-bottom: 20px; }
        .d-none { display: none; }
        .d-flex { display: flex; }
        .gap-10 { gap: 10px; }
        
        /* MODAL */
        .modal { 
            display: none; 
            position: fixed; 
            top: 0; left: 0; 
            width: 100%; height: 100%; 
            background: rgba(0,0,0,0.5); 
            z-index: 1000; 
            align-items: center; 
            justify-content: center; 
        }
        .modal-content { 
            background: white; 
            padding: 20px; 
            border-radius: 10px; 
            min-width: 300px; 
            max-width: 500px; 
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .controls { flex-direction: column; }
            .search-box { min-width: 100%; }
        }
    </style>
</head>
<body>
    <?php if ($page === 'login' || !isLoggedIn()): ?>
        <!-- LOGIN PAGE -->
        <div class="login-container">
            <h2>🔐 HWID Admin Login</h2>
            
            <?php if (isset($login_error)): ?>
                <div class="login-error"><?php echo $login_error; ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required autofocus>
                </div>
                <div class="form-actions">
                    <button type="submit" name="login" class="action-btn btn-primary" style="width: 100%;">
                        Login
                    </button>
                </div>
            </form>
            
            <div class="text-center mt-20 text-muted text-small">
                Default password: admin123<br>
                Change in line 9 of admin_simple.php
            </div>
        </div>
        
    <?php else: ?>
        <!-- MAIN ADMIN DASHBOARD -->
        <div class="header">
            <div>
                <h1>🛡️ HWID License Manager</h1>
                <p class="text-muted">Simple Admin Panel - All in One File</p>
            </div>
            <a href="?logout=1" class="logout-btn">🚪 Logout</a>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Licenses</h3>
                <div class="stat-number total"><?php echo $stats['total']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Active</h3>
                <div class="stat-number active"><?php echo $stats['active']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Expired</h3>
                <div class="stat-number expired"><?php echo $stats['expired']; ?></div>
            </div>
            <div class="stat-card">
                <h3>HWID Bound</h3>
                <div class="stat-number hwid"><?php echo $stats['with_hwid']; ?></div>
            </div>
        </div>
        
        <!-- CONTROLS -->
        <div class="controls">
            <div class="search-box">
                <form method="GET">
                    <input type="hidden" name="filter" value="<?php echo $filter; ?>">
                    <input type="text" name="search" placeholder="Search license or HWID..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </form>
            </div>
            
            <div class="filter-buttons">
                <a href="?filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
                <a href="?filter=active" class="filter-btn <?php echo $filter === 'active' ? 'active' : ''; ?>">Active</a>
                <a href="?filter=expired" class="filter-btn <?php echo $filter === 'expired' ? 'active' : ''; ?>">Expired</a>
                <a href="?filter=with_hwid" class="filter-btn <?php echo $filter === 'with_hwid' ? 'active' : ''; ?>">With HWID</a>
                <a href="?filter=without_hwid" class="filter-btn <?php echo $filter === 'without_hwid' ? 'active' : ''; ?>">No HWID</a>
            </div>
        </div>
        
        <!-- ACTION BUTTONS -->
        <div class="actions">
            <button class="action-btn btn-primary" onclick="showModal('addLicenseModal')">
                ➕ Add New License
            </button>
            <button class="action-btn btn-success" onclick="showModal('addHWIDModal')">
                🖥️ Add HWID Manually
            </button>
            <a href="?bulk_delete_expired=1" class="action-btn btn-danger" 
               onclick="return confirm('Delete ALL expired licenses?')">
                🗑️ Delete All Expired
            </a>
            <button class="action-btn" onclick="showModal('apiInfoModal')">
                🔗 API Info
            </button>
        </div>
        
        <!-- LICENSE TABLE -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>License Key</th>
                        <th>HWID</th>
                        <th>Tier</th>
                        <th>Expires</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($licenses)): ?>
                        <tr>
                            <td colspan="7" class="text-center">No licenses found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($licenses as $license): 
                            $isExpired = strtotime($license['expires']) < time();
                            $hasHWID = !empty($license['hwid']);
                        ?>
                        <tr>
                            <td><?php echo $license['id']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($license['license_key']); ?></strong><br>
                                <small class="text-muted">Created: <?php echo date('Y-m-d', strtotime($license['created_at'])); ?></small>
                            </td>
                            <td>
                                <?php if ($hasHWID): ?>
                                    <code><?php echo htmlspecialchars($license['hwid']); ?></code><br>
                                    <a href="?delete_hwid=<?php echo $license['id']; ?>" 
                                       class="text-small"
                                       onclick="return confirm('Remove HWID? User can login on new device.')">
                                        🗑️ Remove
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-info"><?php echo $license['tier']; ?></span>
                            </td>
                            <td>
                                <?php echo date('Y-m-d H:i', strtotime($license['expires'])); ?><br>
                                <small class="text-muted">
                                    <?php echo $isExpired ? 'Expired' : 'Valid for ' . floor((strtotime($license['expires']) - time()) / 86400) . ' days'; ?>
                                </small>
                            </td>
                            <td>
                                <?php if ($isExpired): ?>
                                    <span class="badge badge-danger">Expired</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Active</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-10">
                                    <a href="?delete_license=<?php echo $license['id']; ?>" 
                                       class="text-small"
                                       onclick="return confirm('Delete this license permanently?')">
                                        ❌ Delete
                                    </a>
                                    <a href="#" class="text-small" 
                                       onclick="editLicense('<?php echo $license['id']; ?>', 
                                               '<?php echo htmlspecialchars($license['license_key']); ?>',
                                               '<?php echo $license['tier']; ?>',
                                               '<?php echo $license['expires']; ?>')">
                                        ✏️ Edit
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- ADD LICENSE MODAL -->
        <div id="addLicenseModal" class="modal">
            <div class="modal-content">
                <h3>➕ Add New License</h3>
                <form method="POST">
                    <div class="form-group">
                        <label>License Key</label>
                        <input type="text" name="license_key" 
                               value="LIC-<?php echo strtoupper(bin2hex(random_bytes(3))); ?>"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label>Tier</label>
                        <select name="tier">
                            <option value="basic">Basic</option>
                            <option value="premium">Premium</option>
                            <option value="ultimate">Ultimate</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Duration</label>
                        <select name="duration">
                            <option value="1">1 Day</option>
                            <option value="7">1 Week</option>
                            <option value="30" selected>1 Month</option>
                            <option value="90">3 Months</option>
                            <option value="180">6 Months</option>
                            <option value="365">1 Year</option>
                            <option value="9999">Lifetime</option>
                        </select>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="action-btn" onclick="hideModal('addLicenseModal')">Cancel</button>
                        <button type="submit" name="add_license" class="action-btn btn-primary">Add License</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- ADD HWID MODAL -->
        <div id="addHWIDModal" class="modal">
            <div class="modal-content">
                <h3>🖥️ Add HWID to License</h3>
                <form method="POST">
                    <div class="form-group">
                        <label>Select License</label>
                        <select name="license_id" required>
                            <option value="">-- Select License --</option>
                            <?php foreach ($licenses as $license): ?>
                                <?php if (empty($license['hwid'])): ?>
                                    <option value="<?php echo $license['id']; ?>">
                                        <?php echo htmlspecialchars($license['license_key']); ?>
                                        (<?php echo $license['tier']; ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>HWID</label>
                        <input type="text" name="hwid" placeholder="Enter HWID..." required>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="action-btn" onclick="hideModal('addHWIDModal')">Cancel</button>
                        <button type="submit" name="add_hwid" class="action-btn btn-success">Add HWID</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- EDIT LICENSE MODAL (HIDDEN FORM) -->
        <div id="editLicenseModal" class="modal">
            <div class="modal-content">
                <h3>✏️ Edit License</h3>
                <form method="POST" id="editLicenseForm">
                    <input type="hidden" name="edit_license_id" id="edit_license_id">
                    
                    <div class="form-group">
                        <label>License Key</label>
                        <input type="text" id="edit_license_key" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Tier</label>
                        <select name="edit_tier" id="edit_tier">
                            <option value="basic">Basic</option>
                            <option value="premium">Premium</option>
                            <option value="ultimate">Ultimate</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>New Expiration Date</label>
                        <input type="datetime-local" name="edit_expires" id="edit_expires" required>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="action-btn" onclick="hideModal('editLicenseModal')">Cancel</button>
                        <button type="submit" name="edit_license" class="action-btn btn-primary">Update License</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- API INFO MODAL -->
        <div id="apiInfoModal" class="modal">
            <div class="modal-content">
                <h3>🔗 API Information</h3>
                <p><strong>Endpoint URL:</strong></p>
                <code style="background: #f8f9fa; padding: 10px; display: block; border-radius: 5px; margin: 10px 0;">
                    <?php 
                    $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
                    $domain = $_SERVER['HTTP_HOST'];
                    $path = dirname($_SERVER['PHP_SELF']);
                    echo $protocol . $domain . $path . '/../api/hwid.php';
                    ?>
                </code>
                
                <p><strong>Usage:</strong></p>
                <code>GET ?key=LICENSE_KEY&hwid=USER_HWID</code>
                
                <p><strong>Example:</strong></p>
                <code>http://yourdomain.com/api/hwid.php?key=LIC-ABC123&hwid=HWID-789XYZ</code>
                
                <div class="form-actions mt-20">
                    <button type="button" class="action-btn" onclick="hideModal('apiInfoModal')">Close</button>
                </div>
            </div>
        </div>
        
        <script>
            // Modal Functions
            function showModal(modalId) {
                document.getElementById(modalId).style.display = 'flex';
            }
            
            function hideModal(modalId) {
                document.getElementById(modalId).style.display = 'none';
            }
            
            // Edit License
            function editLicense(id, key, tier, expires) {
                document.getElementById('edit_license_id').value = id;
                document.getElementById('edit_license_key').value = key;
                document.getElementById('edit_tier').value = tier;
                
                // Convert MySQL datetime to HTML datetime-local format
                const date = new Date(expires);
                const formatted = date.toISOString().slice(0, 16);
                document.getElementById('edit_expires').value = formatted;
                
                showModal('editLicenseModal');
            }
            
            // Auto-submit search on typing
            document.querySelector('input[name="search"]').addEventListener('input', function(e) {
                this.form.submit();
            });
            
            // Close modal on outside click
            window.onclick = function(event) {
                if (event.target.classList.contains('modal')) {
                    event.target.style.display = 'none';
                }
            }
            
            // Auto-generate license key
            function generateLicenseKey() {
                const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';
                let key = 'LIC-';
                for (let i = 0; i < 8; i++) {
                    key += chars.charAt(Math.floor(Math.random() * chars.length));
                    if (i === 3) key += '-';
                }
                document.querySelector('input[name="license_key"]').value = key;
            }
            
            // Auto-focus search on page load
            document.querySelector('input[name="search"]')?.focus();
        </script>
        
        <!-- EDIT LICENSE HANDLER (PHP) -->
        <?php
        if (isset($_POST['edit_license'])) {
            $license_id = $_POST['edit_license_id'];
            $tier = $_POST['edit_tier'];
            $expires = $_POST['edit_expires'];
            
            $stmt = $pdo->prepare("UPDATE licenses SET tier = ?, expires = ? WHERE id = ?");
            if ($stmt->execute([$tier, $expires, $license_id])) {
                echo '<script>
                    alert("License updated successfully!");
                    window.location.reload();
                </script>';
            }
        }
        ?>
        
    <?php endif; ?>
</body>
</html>
