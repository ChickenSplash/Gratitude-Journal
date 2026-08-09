import { createApp } from './src/app.js';
import { startJanitor } from './src/auth.js';

const PORT = Number(process.env.PORT ?? 3000);

startJanitor();

createApp().listen(PORT, '0.0.0.0', () => {
  console.log(`Gratitude Journal listening on http://localhost:${PORT}`);
});
