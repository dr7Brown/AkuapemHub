const express = require('express');
const pool = require('../db');
const authenticate = require('../middleware/auth');
const router = express.Router();

router.get('/', async (req, res) => {
  const { category, query } = req.query;
  let sql = `SELECT l.id, l.title, l.description, l.price, l.location, l.created_at, c.name as category, u.name as owner_name
             FROM listings l
             JOIN categories c ON l.category_id = c.id
             JOIN users u ON l.user_id = u.id`;
  const params = [];
  const filters = [];

  if (category) {
    filters.push('l.category_id = ?');
    params.push(category);
  }
  if (query) {
    filters.push('(l.title LIKE ? OR l.description LIKE ? OR l.location LIKE ?)');
    params.push(`%${query}%`, `%${query}%`, `%${query}%`);
  }

  if (filters.length) {
    sql += ' WHERE ' + filters.join(' AND ');
  }
  sql += ' ORDER BY l.created_at DESC LIMIT 100';

  try {
    const [rows] = await pool.query(sql, params);
    res.json({ listings: rows });
  } catch (error) {
    console.error('Listings fetch error', error);
    res.status(500).json({ error: 'Unable to load listings.' });
  }
});

router.post('/', authenticate, async (req, res) => {
  const { title, description, category_id, location, price } = req.body;
  if (!title || !description || !category_id || !location) {
    return res.status(400).json({ error: 'Title, description, category, and location are required.' });
  }

  try {
    const [result] = await pool.query(
      'INSERT INTO listings (user_id, category_id, title, description, location, price, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
      [req.user.id, category_id, title.trim(), description.trim(), location.trim(), price || 'Free']
    );
    res.status(201).json({ listing: { id: result.insertId, title, description, category_id, location, price } });
  } catch (error) {
    console.error('Create listing error', error);
    res.status(500).json({ error: 'Unable to create listing.' });
  }
});

router.put('/:id', authenticate, async (req, res) => {
  const listingId = req.params.id;
  const { title, description, category_id, location, price } = req.body;
  try {
    const [rows] = await pool.query('SELECT user_id FROM listings WHERE id = ?', [listingId]);
    if (!rows.length) {
      return res.status(404).json({ error: 'Listing not found.' });
    }
    if (rows[0].user_id !== req.user.id) {
      return res.status(403).json({ error: 'Permission denied.' });
    }
    await pool.query(
      'UPDATE listings SET title = ?, description = ?, category_id = ?, location = ?, price = ? WHERE id = ?',
      [title.trim(), description.trim(), category_id, location.trim(), price || 'Free', listingId]
    );
    res.json({ success: true });
  } catch (error) {
    console.error('Update listing error', error);
    res.status(500).json({ error: 'Unable to update listing.' });
  }
});

router.delete('/:id', authenticate, async (req, res) => {
  const listingId = req.params.id;
  try {
    const [rows] = await pool.query('SELECT user_id FROM listings WHERE id = ?', [listingId]);
    if (!rows.length) {
      return res.status(404).json({ error: 'Listing not found.' });
    }
    if (rows[0].user_id !== req.user.id) {
      return res.status(403).json({ error: 'Permission denied.' });
    }
    await pool.query('DELETE FROM listings WHERE id = ?', [listingId]);
    res.json({ success: true });
  } catch (error) {
    console.error('Delete listing error', error);
    res.status(500).json({ error: 'Unable to delete listing.' });
  }
});

module.exports = router;
