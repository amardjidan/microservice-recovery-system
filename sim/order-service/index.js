const express = require('express');
const Database = require('better-sqlite3');
const axios = require('axios');

const db = new Database('order.db');
db.exec(`CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_ref TEXT UNIQUE,
  amount INTEGER,
  payment_synced INTEGER DEFAULT 0,
  created_at TEXT
)`);

const app = express();
app.use(express.json());

app.get('/health', (req, res) => res.json({ status: 'up' }));

app.post('/orders', async (req, res) => {
  const order_ref = 'ORD-' + Date.now() + '-' + Math.floor(Math.random() * 999);
  const amount = Math.floor(Math.random() * 500000) + 10000;

  db.prepare(
    `INSERT INTO orders (order_ref, amount, created_at)
     VALUES (?, ?, datetime('now'))`
  ).run(order_ref, amount);

  try {
    await axios.post('http://localhost:4002/payments', { order_ref, amount }, { timeout: 2000 });
    db.prepare('UPDATE orders SET payment_synced = 1 WHERE order_ref = ?').run(order_ref);
    res.status(201).json({ order_ref, synced: true });
  } catch (e) {
    res.status(201).json({ order_ref, synced: false, error: e.code || 'downstream_error' });
  }
});

app.listen(4001, () => console.log('order-service :4001'));