const express = require('express');
const pool = require('../db');
const router = express.Router();

router.get('/', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT id, name FROM categories ORDER BY name');
    res.json({ categories: rows });
  } catch (error) {
    console.error('Categories error', error);
    res.status(500).json({ error: 'Unable to load categories.' });
  }
});

module.exports = router;
