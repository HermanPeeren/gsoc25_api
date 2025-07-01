import mysql from 'mysql2/promise';

export default function setupPlugins(on, config) {
  // Database task for executing queries
  on('task', {
    async queryDB(query) {
      const connection = await mysql.createConnection({
        host: config.env.db_host,
        user: config.env.db_user,
        password: config.env.db_password,
        database: config.env.db_name,
      });

      try {
        // Replace Joomla table prefix placeholder
        const processedQuery = query.replace(/#__/g, config.env.db_prefix);
        const [rows] = await connection.execute(processedQuery);
        return rows;
      } catch (error) {
        console.error('Database query error:', error);
        throw error;
      } finally {
        await connection.end();
      }
    },
  });

  return config;
}
