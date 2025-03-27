const WebSocket = require('ws');
const mysql = require('mysql2/promise');
const jwt = require('jsonwebtoken');

const pool = mysql.createPool({
  host: 'localhost',
  user: 'root',
  password: '',
  database: 'chat_app',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
});

const JWT_SECRET = 'your-256-bit-secret-key-here';

const wss = new WebSocket.Server({ port: 8080 });

wss.on('connection', async (ws, req) => {
  try {
   
    console.log('New WebSocket connection attempt');

   
    const url = new URL(`http://localhost${req.url}`);
    const token = url.searchParams.get('token');
    const chatId = url.searchParams.get('chat_id');

    console.log('Received connection params:', { token, chatId });

   
    let decoded;
    try {
      decoded = jwt.verify(token, JWT_SECRET);
      console.log('Token decoded successfully:', decoded);
    } catch (tokenError) {
      console.error('Token verification failed:', tokenError);
      ws.close(1008, 'Invalid token');
      return;
    }

 
    ws.userId = decoded.sub; 
    ws.chatId = chatId;


    const [chat] = await pool.query(
      'SELECT id FROM chats WHERE id = ? AND (user1_id = ? OR user2_id = ?)',
      [chatId, ws.userId, ws.userId]
    );
   
    if (!chat.length) {
      console.log(`User ${ws.userId} not authorized for chat ${chatId}`);
      ws.close(1003, 'Unauthorized chat access');
      return;
    }

    
    ws.on('message', async (message) => {
      console.log('Received message:', message.toString());
      try {
        const data = JSON.parse(message);
        switch(data.type) {
          case 'message':
            await handleMessage(ws, data);
            break;
          default:
            console.log('Unknown message type:', data.type);
        }
      } catch (error) {
        console.error('Message handling error:', error);
      }
    });

   
    ws.on('error', (error) => {
      console.error('WebSocket error:', error);
    });

   
    ws.on('close', (code, reason) => {
      console.log(`WebSocket closed: Code ${code}, Reason: ${reason}`);
    });

 
    ws.send(JSON.stringify({
      type: 'connection',
      status: 'success',
      chatId: chatId,
      userId: ws.userId
    }));

    console.log(`User ${ws.userId} connected to chat ${chatId}`);

  } catch (error) {
    console.error('Connection setup error:', error);
    ws.close(1011, 'Internal server error');
  }
});

async function handleMessage(ws, data) {
  try {
   
    if (!data.message || typeof data.message !== 'string' || data.message.trim() === '') {
      console.log('Invalid message received:', data);
      return;
    }

   
    const [result] = await pool.query(
      'INSERT INTO messages (chat_id, sender_id, message) VALUES (?, ?, ?)',
      [ws.chatId, ws.userId, data.message]
    );

    
    const [messages] = await pool.query(`
      SELECT m.*, u.username
      FROM messages m
      JOIN users u ON m.sender_id = u.id
      WHERE m.id = ?
    `, [result.insertId]);

    const messageData = messages[0];

   
    const [participants] = await pool.query(
      'SELECT user1_id AS userId FROM chats WHERE id = ? ' +
      'UNION SELECT user2_id AS userId FROM chats WHERE id = ?',
      [ws.chatId, ws.chatId]
    );

  
    wss.clients.forEach(client => {
      if (client.readyState === WebSocket.OPEN &&
          client.chatId === ws.chatId &&
          participants.some(p => p.userId === client.userId)) {
        try {
          client.send(JSON.stringify({
            type: 'new_message',
            message_id: messageData.id,
            sender_id: messageData.sender_id,
            username: messageData.username,
            message: messageData.message,
            created_at: messageData.created_at
          }));
        } catch (sendError) {
          console.error('Error sending message to client:', sendError);
        }
      }
    });

    console.log(`Message sent in chat ${ws.chatId} by user ${ws.userId}`);

  } catch (error) {
    console.error('Message handling error:', error);
  }
}

console.log('WebSocket server started on port 8080');