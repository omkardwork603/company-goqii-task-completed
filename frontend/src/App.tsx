import { useEffect } from "react";
import api from "./api/tasksApi";

function App() {
  useEffect(() => {
    async function fetchTasks() {
      try {
        const response = await api.get("");

        console.log("API response:", response.data);
      } catch (error: unknown) {
        if (error instanceof Error) {
          console.error("API error:", error.message);
        } else {
          console.error("An unknown API error occurred.");
        }
      }
    }

    fetchTasks();
  }, []);

  return (
    <div>
      <h1>Task Manager</h1>
      <p>Testing PHP API connection...</p>
    </div>
  );
}

export default App;
