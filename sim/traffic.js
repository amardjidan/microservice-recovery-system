const axios = require('axios');

setInterval(async () => {
  try {
    const r = await axios.post('http://localhost:4001/orders');
    console.log(r.data.order_ref, r.data.synced ? 'OK' : 'FAILED');
  } catch (e) {
    console.log('order-service down');
  }
}, 1500);