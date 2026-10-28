import axios from "axios";

const authApi = axios.create({
  baseURL: "http://localhost/Comp/backend/api/auth.php",
  withCredentials: true,
  headers: {
    "Content-Type": "application/json",
    Accept: "application/json",
  },
});

export default authApi;
