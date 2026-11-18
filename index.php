<?php
declare(strict_types=1);
session_start();

/*
 * Dashboard UMKM sederhana.
 * Data tersimpan otomatis pada database SQLite: data/dashboard.sqlite
 * Pastikan folder project dapat ditulis oleh PHP.
 */

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$dbDir = __DIR__ . '/data';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0775, true);
}
$db = new PDO('sqlite:' . $dbDir . '/dashboard.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec("CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    price INTEGER NOT NULL DEFAULT 0,
    stock INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT DEFAULT '',
    email TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_name TEXT NOT NULL,
    product_name TEXT NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1,
    total INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'Diproses',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function rupiah(int $n): string {
    return 'Rp ' . number_format($n, 0, ',', '.');
}
function redirectPage(string $page): never {
    header('Location: ?page=' . urlencode($page));
    exit;
}
function postString(string $key): string {
    return trim((string)($_POST[$key] ?? ''));
}
function verifyCsrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Sesi formulir tidak valid. Silakan kembali dan muat ulang halaman.');
    }
}

$page = (string)($_GET['page'] ?? 'dashboard');
$allowed = ['dashboard', 'products', 'orders', 'customers', 'reports', 'integrations'];
if (!in_array($page, $allowed, true)) $page = 'dashboard';

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = postString('action');

    if ($action === 'save_product') {
        $id = (int)($_POST['id'] ?? 0);
        $name = postString('name');
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_INT);
        $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);

        if ($name === '' || $price === false || $stock === false || $price < 0 || $stock < 0) {
            $_SESSION['flash'] = 'Isi nama barang, harga, dan stok dengan benar.';
        } elseif ($id > 0) {
            $stmt = $db->prepare('UPDATE products SET name=?, price=?, stock=? WHERE id=?');
            $stmt->execute([$name, $price, $stock, $id]);
            $_SESSION['flash'] = 'Produk berhasil diperbarui.';
        } else {
            $stmt = $db->prepare('INSERT INTO products(name, price, stock) VALUES(?,?,?)');
            $stmt->execute([$name, $price, $stock]);
            $_SESSION['flash'] = 'Produk berhasil disimpan.';
        }
        redirectPage('products');
    }

    if ($action === 'delete_product') {
        $stmt = $db->prepare('DELETE FROM products WHERE id=?');
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        $_SESSION['flash'] = 'Produk berhasil dihapus.';
        redirectPage('products');
    }

    if ($action === 'save_customer') {
        $id = (int)($_POST['id'] ?? 0);
        $name = postString('customer_name');
        $phone = postString('phone');
        $email = postString('email');
        if ($name === '') {
            $_SESSION['flash'] = 'Nama pelanggan wajib diisi.';
        } elseif ($id > 0) {
            $stmt = $db->prepare('UPDATE customers SET name=?, phone=?, email=? WHERE id=?');
            $stmt->execute([$name, $phone, $email, $id]);
            $_SESSION['flash'] = 'Data pelanggan berhasil diperbarui.';
        } else {
            $stmt = $db->prepare('INSERT INTO customers(name, phone, email) VALUES(?,?,?)');
            $stmt->execute([$name, $phone, $email]);
            $_SESSION['flash'] = 'Pelanggan berhasil disimpan.';
        }
        redirectPage('customers');
    }

    if ($action === 'delete_customer') {
        $stmt = $db->prepare('DELETE FROM customers WHERE id=?');
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        $_SESSION['flash'] = 'Pelanggan berhasil dihapus.';
        redirectPage('customers');
    }

    if ($action === 'save_order') {
        $customer = postString('customer_name');
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $productStmt = $db->prepare('SELECT * FROM products WHERE id=?');
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if ($customer === '' || !$product || $qty === false || $qty < 1) {
            $_SESSION['flash'] = 'Lengkapi nama pelanggan, produk, dan jumlah pesanan.';
        } elseif ((int)$product['stock'] < $qty) {
            $_SESSION['flash'] = 'Stok produk tidak mencukupi.';
        } else {
            $total = (int)$product['price'] * $qty;
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT INTO orders(customer_name, product_name, quantity, total) VALUES(?,?,?,?)');
            $stmt->execute([$customer, $product['name'], $qty, $total]);
            $upd = $db->prepare('UPDATE products SET stock=stock-? WHERE id=?');
            $upd->execute([$qty, $productId]);
            $db->commit();
            $_SESSION['flash'] = 'Pesanan berhasil dibuat.';
        }
        redirectPage('orders');
    }

    if ($action === 'edit_order') {
        $id = (int)($_POST['id'] ?? 0);
        $customer = postString('customer_name');
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $oldStmt = $db->prepare('SELECT * FROM orders WHERE id=?');
        $oldStmt->execute([$id]);
        $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
        $newStmt = $db->prepare('SELECT * FROM products WHERE id=?');
        $newStmt->execute([$productId]);
        $newProduct = $newStmt->fetch(PDO::FETCH_ASSOC);

        if (!$old || $customer === '' || !$newProduct || $qty === false || $qty < 1) {
            $_SESSION['flash'] = 'Data pesanan belum lengkap atau tidak valid.';
        } else {
            $oldProductStmt = $db->prepare('SELECT * FROM products WHERE name=? ORDER BY id LIMIT 1');
            $oldProductStmt->execute([$old['product_name']]);
            $oldProduct = $oldProductStmt->fetch(PDO::FETCH_ASSOC);
            $available = (int)$newProduct['stock'];
            if ($old['status'] !== 'Dibatalkan' && $oldProduct && (int)$oldProduct['id'] === $productId) {
                $available += (int)$old['quantity'];
            }
            if ($available < $qty) {
                $_SESSION['flash'] = 'Stok produk tidak mencukupi untuk perubahan pesanan.';
            } else {
                $db->beginTransaction();
                if ($old['status'] !== 'Dibatalkan' && $oldProduct) {
                    $restore = $db->prepare('UPDATE products SET stock=stock+? WHERE id=?');
                    $restore->execute([(int)$old['quantity'], (int)$oldProduct['id']]);
                }
                if ($old['status'] !== 'Dibatalkan') {
                    $deduct = $db->prepare('UPDATE products SET stock=stock-? WHERE id=?');
                    $deduct->execute([$qty, $productId]);
                }
                $update = $db->prepare('UPDATE orders SET customer_name=?, product_name=?, quantity=?, total=? WHERE id=?');
                $update->execute([$customer, $newProduct['name'], $qty, (int)$newProduct['price'] * $qty, $id]);
                $db->commit();
                $_SESSION['flash'] = 'Pesanan berhasil diperbarui.';
            }
        }
        redirectPage('orders');
    }

    if ($action === 'delete_order') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM orders WHERE id=?');
        $stmt->execute([$id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order) {
            $db->beginTransaction();
            if ($order['status'] !== 'Dibatalkan') {
                $restore = $db->prepare('UPDATE products SET stock=stock+? WHERE name=?');
                $restore->execute([(int)$order['quantity'], $order['product_name']]);
            }
            $del = $db->prepare('DELETE FROM orders WHERE id=?');
            $del->execute([$id]);
            $db->commit();
            $_SESSION['flash'] = 'Pesanan berhasil dihapus.';
        }
        redirectPage('orders');
    }

    if ($action === 'update_order_status') {
        $status = postString('status');
        $id = (int)($_POST['id'] ?? 0);
        if (in_array($status, ['Diproses', 'Selesai', 'Dibatalkan'], true)) {
            $find = $db->prepare('SELECT * FROM orders WHERE id=?');
            $find->execute([$id]);
            $order = $find->fetch(PDO::FETCH_ASSOC);
            if ($order) {
                $prodFind = $db->prepare('SELECT * FROM products WHERE name=? ORDER BY id LIMIT 1');
                $prodFind->execute([$order['product_name']]);
                $prod = $prodFind->fetch(PDO::FETCH_ASSOC);
                if ($prod && $order['status'] !== 'Dibatalkan' && $status === 'Dibatalkan') {
                    $restore = $db->prepare('UPDATE products SET stock=stock+? WHERE id=?');
                    $restore->execute([(int)$order['quantity'], (int)$prod['id']]);
                } elseif ($prod && $order['status'] === 'Dibatalkan' && $status !== 'Dibatalkan') {
                    if ((int)$prod['stock'] < (int)$order['quantity']) {
                        $_SESSION['flash'] = 'Stok tidak cukup untuk mengaktifkan kembali pesanan.';
                        redirectPage('orders');
                    }
                    $deduct = $db->prepare('UPDATE products SET stock=stock-? WHERE id=?');
                    $deduct->execute([(int)$order['quantity'], (int)$prod['id']]);
                }
                $stmt = $db->prepare('UPDATE orders SET status=? WHERE id=?');
                $stmt->execute([$status, $id]);
                $_SESSION['flash'] = 'Status pesanan diperbarui.';
            }
        }
        redirectPage('orders');
    }
}

$flash = (string)($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);

$products = $db->query('SELECT * FROM products ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$customers = $db->query('SELECT * FROM customers ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$orders = $db->query('SELECT * FROM orders ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$productCount = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
$customerCount = (int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$orderCount = (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$revenue = (int)$db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status != 'Dibatalkan'")->fetchColumn();
$editProduct = null;
if ($page === 'products' && isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM products WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editProduct = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$editCustomer = null;
if ($page === 'customers' && isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM customers WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editCustomer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$editOrder = null;
if ($page === 'orders' && isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM orders WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editOrder = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard UMKM</title>
<style>
:root{--blue:#2878e8;--blue2:#eaf2ff;--ink:#263445;--muted:#718096;--line:#e8edf4;--bg:#f7f9fc;--white:#fff;--green:#16a36a;--red:#dc5454}
*{box-sizing:border-box}body{margin:0;font-family:Inter,Segoe UI,Arial,sans-serif;color:var(--ink);background:var(--bg)}
.layout{display:flex;min-height:100vh}.sidebar{width:220px;background:#fff;border-right:1px solid var(--line);padding:22px 14px;flex-shrink:0}
.brand{font-size:18px;font-weight:800;padding:4px 12px 26px;color:#24364b}.brand span{color:var(--blue)}
.nav a{display:flex;gap:12px;align-items:center;padding:11px 12px;margin:3px 0;border-radius:8px;text-decoration:none;color:#5482c4;font-size:14px;transition:.15s}
.nav a:hover,.nav a.active{background:var(--blue2);color:var(--blue);font-weight:650}.nav .ico{width:18px;text-align:center}
.main{flex:1;min-width:0;padding:26px 30px}.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
h1{font-size:23px;margin:0 0 6px;font-weight:700}.subtitle{font-size:13px;color:var(--muted)}
.user{background:#fff;border:1px solid var(--line);border-radius:30px;padding:8px 13px;font-size:13px;color:#526174}
.panel{background:#fff;border:1px solid var(--line);border-radius:12px;padding:22px;margin-bottom:20px;box-shadow:0 2px 8px rgba(28,48,75,.025)}
.panel h2{font-size:17px;margin:0 0 20px}.form-grid{display:grid;grid-template-columns:1fr;gap:14px}
label{display:block;font-size:13px;font-weight:600;margin-bottom:7px;color:#465468}
input,select{width:100%;height:42px;border:1px solid #dce3ed;border-radius:7px;padding:0 12px;font:inherit;font-size:14px;background:#fff;color:var(--ink);outline:none}
input:focus,select:focus{border-color:#82aef4;box-shadow:0 0 0 3px rgba(40,120,232,.13)}
.actions{display:flex;gap:9px;align-items:center;margin-top:17px;flex-wrap:wrap}
.btn{border:0;border-radius:7px;background:var(--blue);color:#fff;padding:10px 16px;font-weight:600;font-size:13px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.btn:hover{filter:brightness(.95)}.btn.secondary{background:#edf2f8;color:#43536a}.btn.danger{background:#fff0f0;color:var(--red)}.btn.small{padding:7px 10px;font-size:12px}
.notice{padding:12px 14px;border-radius:8px;background:#edf8f2;color:#16764e;font-size:13px;margin-bottom:16px}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:20px}
.stat{background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px}.stat .label{font-size:13px;color:var(--muted)}.stat .value{font-size:24px;font-weight:750;margin-top:8px}
.table-wrap{overflow:auto}table{border-collapse:collapse;width:100%;font-size:13px;min-width:570px}th,td{text-align:left;padding:13px 12px;border-bottom:1px solid var(--line)}th{font-size:12px;color:#778397;background:#fbfcfe;font-weight:650}tr:last-child td{border-bottom:0}
.empty{text-align:center;color:var(--muted);padding:28px}.pill{display:inline-block;border-radius:20px;padding:5px 9px;font-size:11px;background:#edf4ff;color:#326fbd}
.two-col{display:grid;grid-template-columns:minmax(300px, .9fr) minmax(420px,1.5fr);gap:18px;align-items:start}
.hint{font-size:12px;color:var(--muted);margin-top:6px}.inline-form{display:inline}
@media(max-width:900px){.sidebar{width:185px}.main{padding:22px 18px}.stats{grid-template-columns:repeat(2,1fr)}.two-col{grid-template-columns:1fr}}
@media(max-width:600px){.layout{display:block}.sidebar{width:100%;padding:10px}.brand{padding:8px 10px 12px}.nav{display:flex;overflow:auto}.nav a{white-space:nowrap;padding:9px}.main{padding:18px 13px}.stats{grid-template-columns:1fr 1fr}.topbar{align-items:flex-start}.user{display:none}.panel{padding:16px}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <div class="brand">UMKM<span>Panel</span></div>
    <nav class="nav">
        <a class="<?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard"><span class="ico">▦</span> Dashboard</a>
        <a class="<?= $page==='orders'?'active':'' ?>" href="?page=orders"><span class="ico">🛒</span> Orders</a>
        <a class="<?= $page==='products'?'active':'' ?>" href="?page=products"><span class="ico">◇</span> Products</a>
        <a class="<?= $page==='customers'?'active':'' ?>" href="?page=customers"><span class="ico">♧</span> Customers</a>
        <a class="<?= $page==='reports'?'active':'' ?>" href="?page=reports"><span class="ico">▥</span> Reports</a>
        <a class="<?= $page==='integrations'?'active':'' ?>" href="?page=integrations"><span class="ico">⌘</span> Integrations</a>
    </nav>
</aside>
<main class="main">
<div class="topbar">
    <div><h1><?= ['dashboard'=>'Dashboard','products'=>'Form Produk','orders'=>'Orders','customers'=>'Customers','reports'=>'Reports','integrations'=>'Integrations'][$page] ?></h1>
    <div class="subtitle">Kelola data usaha dengan mudah.</div></div>
    <div class="user">● Admin UMKM</div>
</div>
<?php if ($flash): ?><div class="notice"><?= e($flash) ?></div><?php endif; ?>

<?php if ($page === 'dashboard'): ?>
    <section class="stats">
        <div class="stat"><div class="label">Total Produk</div><div class="value"><?= $productCount ?></div></div>
        <div class="stat"><div class="label">Total Pesanan</div><div class="value"><?= $orderCount ?></div></div>
        <div class="stat"><div class="label">Pelanggan</div><div class="value"><?= $customerCount ?></div></div>
        <div class="stat"><div class="label">Total Penjualan</div><div class="value" style="font-size:20px"><?= rupiah($revenue) ?></div></div>
    </section>
    <section class="panel">
        <h2>Selamat datang di Dashboard UMKM</h2>
        <p style="font-size:14px;color:#66758a;line-height:1.7">Gunakan menu di sebelah kiri untuk menambah produk, mencatat pesanan, mengelola pelanggan, dan melihat laporan. Data akan tersimpan di database lokal.</p>
        <div class="actions"><a class="btn" href="?page=products">＋ Tambah Produk</a><a class="btn secondary" href="?page=orders">Buat Pesanan</a><a class="btn secondary" href="?page=customers">Tambah Pelanggan</a></div>
    </section>
    <section class="panel"><h2>Produk Terbaru</h2><div class="table-wrap"><table><thead><tr><th>Nama Barang</th><th>Harga</th><th>Stok</th></tr></thead><tbody>
    <?php if (!$products): ?><tr><td colspan="3" class="empty">Belum ada produk. Klik Tambah Produk untuk memulai.</td></tr><?php else: foreach (array_slice($products,0,5) as $p): ?><tr><td><?=e($p['name'])?></td><td><?=rupiah((int)$p['price'])?></td><td><?= (int)$p['stock'] ?></td></tr><?php endforeach; endif; ?>
    </tbody></table></div></section>

<?php elseif ($page === 'products'): ?>
    <div class="two-col">
    <section class="panel">
        <h2><?= $editProduct ? 'Edit Produk' : 'Form Produk' ?></h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>">
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="id" value="<?= (int)($editProduct['id'] ?? 0) ?>">
            <div class="form-grid">
                <div><label for="name">Nama Barang</label><input id="name" name="name" placeholder="Masukkan nama" required value="<?=e((string)($editProduct['name'] ?? ''))?>"></div>
                <div><label for="price">Harga</label><input id="price" name="price" type="number" min="0" step="1" placeholder="Masukkan harga" required value="<?=e((string)($editProduct['price'] ?? ''))?>"></div>
                <div><label for="stock">Stok</label><input id="stock" name="stock" type="number" min="0" step="1" placeholder="Masukkan jumlah stok" required value="<?=e((string)($editProduct['stock'] ?? ''))?>"></div>
            </div>
            <div class="actions"><button class="btn" type="submit"><?= $editProduct ? 'Simpan Perubahan' : 'Simpan' ?></button><?php if($editProduct): ?><a class="btn secondary" href="?page=products">Batal</a><?php endif; ?></div>
        </form>
    </section>
    <section class="panel"><h2>Daftar Produk</h2><div class="table-wrap"><table><thead><tr><th>Nama Barang</th><th>Harga</th><th>Stok</th><th>Aksi</th></tr></thead><tbody>
    <?php if(!$products): ?><tr><td colspan="4" class="empty">Belum ada data produk.</td></tr><?php else: foreach($products as $p): ?><tr><td><?=e($p['name'])?></td><td><?=rupiah((int)$p['price'])?></td><td><?= (int)$p['stock'] ?></td><td><a class="btn small secondary" href="?page=products&edit=<?=(int)$p['id']?>">Edit</a> <form class="inline-form" method="post" onsubmit="return confirm('Hapus produk ini?')"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="<?=(int)$p['id']?>"><button class="btn small danger" type="submit">Hapus</button></form></td></tr><?php endforeach; endif; ?>
    </tbody></table></div></section></div>

<?php elseif ($page === 'orders'): ?>
    <div class="two-col">
    <section class="panel"><h2><?= $editOrder ? 'Edit Pesanan' : 'Buat Pesanan' ?></h2>
        <form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="<?= $editOrder ? 'edit_order' : 'save_order' ?>">
        <?php if($editOrder): ?><input type="hidden" name="id" value="<?=(int)$editOrder['id']?>"><?php endif; ?>
        <div class="form-grid">
            <div><label>Nama Pelanggan</label><input name="customer_name" placeholder="Masukkan nama pelanggan" value="<?=e((string)($editOrder['customer_name'] ?? ''))?>" required></div>
            <div><label>Produk</label><select name="product_id" required><option value="">Pilih produk</option><?php foreach($products as $p): ?><option value="<?=(int)$p['id']?>" <?= ($editOrder && $editOrder['product_name']===$p['name'])?'selected':'' ?>><?=e($p['name'])?> — <?=rupiah((int)$p['price'])?> (stok: <?=(int)$p['stock']?>)</option><?php endforeach; ?></select></div>
            <div><label>Jumlah</label><input name="quantity" type="number" min="1" value="<?=e((string)($editOrder['quantity'] ?? 1))?>" required></div>
        </div><div class="actions"><button class="btn" type="submit"><?= $editOrder ? 'Simpan Perubahan' : 'Simpan Pesanan' ?></button><?php if($editOrder): ?><a class="btn secondary" href="?page=orders">Batal</a><?php endif; ?></div>
        <?php if(!$products): ?><div class="hint">Tambahkan produk terlebih dahulu agar dapat membuat pesanan.</div><?php endif; ?>
        </form>
    </section>
    <section class="panel"><h2>Daftar Pesanan</h2><div class="table-wrap"><table><thead><tr><th>Pelanggan</th><th>Produk</th><th>Jumlah</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php if(!$orders): ?><tr><td colspan="6" class="empty">Belum ada pesanan.</td></tr><?php else: foreach($orders as $o): ?><tr><td><?=e($o['customer_name'])?></td><td><?=e($o['product_name'])?></td><td><?=(int)$o['quantity']?></td><td><?=rupiah((int)$o['total'])?></td><td><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="update_order_status"><input type="hidden" name="id" value="<?=(int)$o['id']?>"><select name="status" onchange="this.form.submit()" style="width:auto;height:30px;font-size:12px"><option <?= $o['status']==='Diproses'?'selected':'' ?>>Diproses</option><option <?= $o['status']==='Selesai'?'selected':'' ?>>Selesai</option><option <?= $o['status']==='Dibatalkan'?'selected':'' ?>>Dibatalkan</option></select></form></td><td><a class="btn small secondary" href="?page=orders&edit=<?=(int)$o['id']?>">Edit</a> <form method="post" class="inline-form" onsubmit="return confirm('Hapus pesanan ini?')"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="delete_order"><input type="hidden" name="id" value="<?=(int)$o['id']?>"><button class="btn small danger" type="submit">Hapus</button></form></td></tr><?php endforeach; endif; ?>
    </tbody></table></div></section></div>

<?php elseif ($page === 'customers'): ?>
    <div class="two-col">
    <section class="panel"><h2><?= $editCustomer ? 'Edit Pelanggan' : 'Tambah Pelanggan' ?></h2><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="save_customer"><input type="hidden" name="id" value="<?=(int)($editCustomer['id'] ?? 0)?>">
    <div class="form-grid"><div><label>Nama Pelanggan</label><input name="customer_name" placeholder="Masukkan nama" value="<?=e((string)($editCustomer['name'] ?? ''))?>" required></div><div><label>Nomor Telepon</label><input name="phone" placeholder="08xxxxxxxxxx" value="<?=e((string)($editCustomer['phone'] ?? ''))?>"></div><div><label>Email</label><input name="email" type="email" placeholder="nama@email.com" value="<?=e((string)($editCustomer['email'] ?? ''))?>"></div></div>
    <div class="actions"><button class="btn" type="submit"><?= $editCustomer ? 'Simpan Perubahan' : 'Simpan Pelanggan' ?></button><?php if($editCustomer): ?><a class="btn secondary" href="?page=customers">Batal</a><?php endif; ?></div></form></section>
    <section class="panel"><h2>Daftar Pelanggan</h2><div class="table-wrap"><table><thead><tr><th>Nama</th><th>Telepon</th><th>Email</th><th>Aksi</th></tr></thead><tbody>
    <?php if(!$customers): ?><tr><td colspan="4" class="empty">Belum ada pelanggan.</td></tr><?php else: foreach($customers as $c): ?><tr><td><?=e($c['name'])?></td><td><?=e($c['phone'])?></td><td><?=e($c['email'])?></td><td><a class="btn small secondary" href="?page=customers&edit=<?=(int)$c['id']?>">Edit</a> <form method="post" class="inline-form" onsubmit="return confirm('Hapus pelanggan ini?')"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="delete_customer"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><button class="btn small danger" type="submit">Hapus</button></form></td></tr><?php endforeach; endif; ?>
    </tbody></table></div></section></div>

<?php elseif ($page === 'reports'): ?>
    <section class="stats"><div class="stat"><div class="label">Produk</div><div class="value"><?=$productCount?></div></div><div class="stat"><div class="label">Pesanan</div><div class="value"><?=$orderCount?></div></div><div class="stat"><div class="label">Pelanggan</div><div class="value"><?=$customerCount?></div></div><div class="stat"><div class="label">Penjualan (non-batal)</div><div class="value" style="font-size:20px"><?=rupiah($revenue)?></div></div></section>
    <section class="panel"><h2>Ringkasan Penjualan</h2><div class="table-wrap"><table><thead><tr><th>Nama Produk</th><th>Total Unit Terjual</th><th>Total Penjualan</th></tr></thead><tbody>
    <?php $summary=$db->query("SELECT product_name, SUM(quantity) qty, SUM(total) total FROM orders WHERE status!='Dibatalkan' GROUP BY product_name ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC); ?>
    <?php if(!$summary): ?><tr><td colspan="3" class="empty">Belum ada transaksi untuk dilaporkan.</td></tr><?php else: foreach($summary as $s): ?><tr><td><?=e($s['product_name'])?></td><td><?=(int)$s['qty']?></td><td><?=rupiah((int)$s['total'])?></td></tr><?php endforeach; endif; ?>
    </tbody></table></div><div class="actions"><button class="btn secondary" onclick="window.print()">Cetak Laporan</button></div></section>

<?php elseif ($page === 'integrations'): ?>
    <section class="panel"><h2>Integrations</h2><p style="font-size:14px;color:#66758a;line-height:1.7">Bagian ini merupakan tempat menyiapkan koneksi layanan lain. Pada versi demo ini belum ada layanan eksternal yang dihubungkan.</p>
    <div class="stats"><div class="stat"><div class="label">WhatsApp</div><div class="value" style="font-size:16px">Belum terhubung</div><button class="btn small secondary" type="button" onclick="alert('Integrasi WhatsApp dapat ditambahkan melalui API resmi.')">Pengaturan</button></div><div class="stat"><div class="label">Email</div><div class="value" style="font-size:16px">Belum terhubung</div><button class="btn small secondary" type="button" onclick="alert('Isi konfigurasi SMTP untuk mengaktifkan email.')">Pengaturan</button></div><div class="stat"><div class="label">Pembayaran</div><div class="value" style="font-size:16px">Belum terhubung</div><button class="btn small secondary" type="button" onclick="alert('Payment gateway memerlukan akun dan API key.')">Pengaturan</button></div></div></section>
<?php endif; ?>
</main>
</div>
</body>
</html>
