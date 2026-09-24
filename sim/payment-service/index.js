const express = require('express');
const Database = require('better-sqlite3');

const db = new Database('payment.db');
db.exec(`CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_ref TEXT UNIQUE,
  amount INTEGER,
  status TEXT,
  created_at TEXT
)`);

const app = express();
app.use(express.json());

app.get('/health', (req, res) => {
  try {
    db.prepare('SELECT 1').get();
    res.json({ status: 'up' });
  } catch (e) {
    res.status(503).json({ status: 'down', error: 'db_unreachable' });
  }
});

app.post('/payments', (req, res) => {
  const { order_ref, amount } = req.body;
  try {
    db.prepare(
      `INSERT INTO payments (order_ref, amount, status, created_at)
       VALUES (?, ?, 'paid', datetime('now'))`
    ).run(order_ref, amount);
    res.status(201).json({ ok: true, order_ref });
  } catch (e) {
    if (String(e).includes('UNIQUE')) return res.json({ ok: true, duplicate: true });
    res.status(500).json({ ok: false, error: String(e) });
  }
});

app.listen(4002, () => console.log('payment-service :4002'));