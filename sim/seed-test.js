const Database = require('better-sqlite3');
const path = require('path');

const orderDb = new Database(path.join(__dirname, 'order-service', 'order.db'));
const paymentDb = new Database(path.join(__dirname, 'payment-service', 'payment.db'));

const now = new Date().toISOString().replace('T', ' ').slice(0, 19);

// --- Skenario: INCONSISTENT ---
orderDb.prepare(`
  INSERT INTO orders (order_ref, amount, payment_synced, created_at)
  VALUES (?, ?, 1, ?)
`).run('TEST-INCONSISTENT-001', 100000, now);

paymentDb.prepare(`
  INSERT INTO payments (order_ref, amount, status, created_at)
  VALUES (?, ?, 'paid', ?)
`).run('TEST-INCONSISTENT-001', 75000, now);

console.log('Data test berhasil dibuat pada waktu:', now);