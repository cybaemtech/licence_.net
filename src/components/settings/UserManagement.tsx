import { useState, useEffect } from 'react';
import { UserPlus, Edit2, Trash2, Eye, EyeOff, ChevronDown, ChevronUp } from 'lucide-react';
import { apiRequest } from '../../utils/apiHelper';
import { getDefaultPermissions } from '../../utils/permissionHelper';

interface ModulePermission {
  access: boolean;
  actions?: {
    create?: boolean;
    read?: boolean;
    update?: boolean;
    delete?: boolean;
  };
}

interface User {
  id: string;
  email: string;
  role: string;
  permissions: {
    dashboard?: ModulePermission | boolean;
    licenses?: ModulePermission | boolean;
    sales?: ModulePermission | boolean;
    clients?: ModulePermission | boolean;
    vendors?: ModulePermission | boolean;
    reports?: ModulePermission | boolean;
    teams?: ModulePermission | boolean;
    settings?: ModulePermission | boolean;
    notifications?: ModulePermission | boolean;
  };
  created_at: string;
}

interface NewUser {
  email: string;
  password: string;
  role: string;
  permissions: {
    dashboard?: ModulePermission | boolean;
    licenses?: ModulePermission | boolean;
    sales?: ModulePermission | boolean;
    clients?: ModulePermission | boolean;
    vendors?: ModulePermission | boolean;
    reports?: ModulePermission | boolean;
    teams?: ModulePermission | boolean;
    settings?: ModulePermission | boolean;
    notifications?: ModulePermission | boolean;
  };
}

function UserManagement() {
  const [users, setUsers] = useState<User[]>([]);
  const [isCreating, setIsCreating] = useState(false);
  const [editingUser, setEditingUser] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  
  const [newUser, setNewUser] = useState<NewUser>({
    email: '',
    password: '',
    role: 'user',
    permissions: getDefaultPermissions('user'),
  });
  
  const [expandedModules, setExpandedModules] = useState<Record<string, boolean>>({});

  const [editFormData, setEditFormData] = useState<Partial<NewUser>>({});

  useEffect(() => {
    fetchUsers();
  }, []);

  const fetchUsers = async () => {
    try {
      setLoading(true);
      const response = await apiRequest('/users');
      if (response.success) {
        setUsers(response.data);
      }
    } catch (error) {
      console.error('Error fetching users:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleCreateUser = async () => {
    if (!newUser.email) {
      alert('Email is required');
      return;
    }
    
    // If editing, password is optional; if creating, password is required
    if (!editingUser && !newUser.password) {
      alert('Password is required for new users');
      return;
    }

    try {
      setLoading(true);
      
      if (editingUser) {
        // Update existing user
        const updateData: any = {
          email: newUser.email,
          role: newUser.role,
          permissions: newUser.permissions,
        };
        
        // Only include password if it was changed
        if (newUser.password) {
          updateData.password = newUser.password;
        }
        
        const response = await apiRequest(`/users/${editingUser}`, {
          method: 'PUT',
          body: JSON.stringify(updateData),
        });

        if (response.success) {
          alert('User updated successfully!');
          // Dispatch event to refresh permissions for all users
          window.dispatchEvent(new Event('permissionsUpdated'));
          cancelEdit();
          fetchUsers();
        }
      } else {
        // Create new user
        const response = await apiRequest('/users', {
          method: 'POST',
          body: JSON.stringify(newUser),
        });

        if (response.success) {
          alert('User created successfully!');
          // Dispatch event to refresh permissions
          window.dispatchEvent(new Event('permissionsUpdated'));
          setIsCreating(false);
          setNewUser({
            email: '',
            password: '',
            role: 'user',
            permissions: getDefaultPermissions('user'),
          });
          fetchUsers();
        }
      }
    } catch (error: any) {
      alert(error.message || `Failed to ${editingUser ? 'update' : 'create'} user`);
    } finally {
      setLoading(false);
    }
  };


  const handleDeleteUser = async (userId: string) => {
    if (!confirm('Are you sure you want to delete this user?')) {
      return;
    }

    try {
      setLoading(true);
      const response = await apiRequest(`/users/${userId}`, {
        method: 'DELETE',
      });

      if (response.success) {
        alert('User deleted successfully!');
        fetchUsers();
      }
    } catch (error: any) {
      alert(error.message || 'Failed to delete user');
    } finally {
      setLoading(false);
    }
  };

  const startEdit = (user: User) => {
    // Open the create form in edit mode
    setEditingUser(user.id);
    setNewUser({
      email: user.email,
      password: '', // Don't prefill password
      role: user.role,
      permissions: user.permissions,
    });
    setIsCreating(true); // Reuse the create form
  };

  const cancelEdit = () => {
    setEditingUser(null);
    setEditFormData({});
    setIsCreating(false);
    setNewUser({
      email: '',
      password: '',
      role: 'user',
      permissions: getDefaultPermissions('user'),
    });
  };

  const permissionsList = [
    { key: 'dashboard', label: 'Dashboard', hasActions: false },
    { key: 'licenses', label: 'Licenses', hasActions: true },
    { key: 'sales', label: 'Sales', hasActions: true },
    { key: 'clients', label: 'Clients', hasActions: true },
    { key: 'vendors', label: 'Vendors', hasActions: true },
    { key: 'reports', label: 'Reports', hasActions: false },
    { key: 'teams', label: 'Teams', hasActions: false },
    { key: 'settings', label: 'Settings', hasActions: false },
    { key: 'notifications', label: 'Notifications', hasActions: false },
  ];

  const actionsList = [
    { key: 'create', label: 'Create', icon: '➕' },
    { key: 'read', label: 'Read', icon: '👁️' },
    { key: 'update', label: 'Update', icon: '✏️' },
    { key: 'delete', label: 'Delete', icon: '🗑️' },
  ];

  const toggleModuleExpand = (moduleKey: string) => {
    setExpandedModules(prev => ({
      ...prev,
      [moduleKey]: !prev[moduleKey]
    }));
  };

  const toggleModuleAccess = (moduleKey: string, isNewUser: boolean = true) => {
    const permissions = isNewUser ? newUser.permissions : editFormData.permissions;
    if (!permissions) return;

    const currentPerm = permissions[moduleKey as keyof typeof permissions];
    const hasActions = permissionsList.find(p => p.key === moduleKey)?.hasActions;

    let newPerm: ModulePermission | boolean;
    
    if (typeof currentPerm === 'boolean') {
      // Convert old boolean to new structure
      if (hasActions) {
        newPerm = {
          access: !currentPerm,
          actions: { create: !currentPerm, read: !currentPerm, update: !currentPerm, delete: !currentPerm }
        };
      } else {
        newPerm = { access: !currentPerm };
      }
    } else if (currentPerm && typeof currentPerm === 'object') {
      newPerm = { ...currentPerm, access: !currentPerm.access };
    } else {
      newPerm = hasActions 
        ? { access: true, actions: { create: true, read: true, update: true, delete: true } }
        : { access: true };
    }

    if (isNewUser) {
      setNewUser({ ...newUser, permissions: { ...permissions, [moduleKey]: newPerm } });
    } else {
      setEditFormData({ ...editFormData, permissions: { ...permissions, [moduleKey]: newPerm } });
    }
  };

  const toggleAction = (moduleKey: string, actionKey: string, isNewUser: boolean = true) => {
    const permissions = isNewUser ? newUser.permissions : editFormData.permissions;
    if (!permissions) return;

    const currentPerm = permissions[moduleKey as keyof typeof permissions];
    
    if (typeof currentPerm === 'object' && currentPerm.actions) {
      const newActions = { 
        ...currentPerm.actions, 
        [actionKey]: !currentPerm.actions[actionKey as keyof typeof currentPerm.actions] 
      };
      const newPerm = { ...currentPerm, actions: newActions };

      if (isNewUser) {
        setNewUser({ ...newUser, permissions: { ...permissions, [moduleKey]: newPerm } });
      } else {
        setEditFormData({ ...editFormData, permissions: { ...permissions, [moduleKey]: newPerm } });
      }
    }
  };

  const getModuleAccess = (perm: ModulePermission | boolean | undefined): boolean => {
    if (typeof perm === 'boolean') return perm;
    return perm?.access || false;
  };

  const getActionValue = (perm: ModulePermission | boolean | undefined, action: string): boolean => {
    if (typeof perm === 'boolean') return perm;
    if (perm && typeof perm === 'object' && perm.actions) {
      return perm.actions[action as keyof typeof perm.actions] || false;
    }
    return false;
  };

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h2 className="text-xl font-semibold text-gray-900 dark:text-white">User Management</h2>
        <button
          onClick={() => setIsCreating(!isCreating)}
          className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          <UserPlus className="h-5 w-5 mr-2" />
          {isCreating ? 'Cancel' : 'Create New User'}
        </button>
      </div>

      {isCreating && (
        <div className="bg-gray-50 dark:bg-gray-700 p-6 rounded-lg space-y-4">
          <h3 className="text-lg font-medium text-gray-900 dark:text-white">
            {editingUser ? 'Update User' : 'Create New User'}
          </h3>
          
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Email Address *
              </label>
              <input
                type="email"
                value={newUser.email}
                onChange={(e) => setNewUser({ ...newUser, email: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-800 dark:text-white"
                placeholder="user@example.com"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Password {editingUser ? '(Optional)' : '*'}
              </label>
              <div className="relative">
                <input
                  type={showPassword ? 'text' : 'password'}
                  value={newUser.password}
                  onChange={(e) => setNewUser({ ...newUser, password: e.target.value })}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-800 dark:text-white pr-10"
                  placeholder={editingUser ? "Leave blank to keep current password" : "Enter password"}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700 dark:text-gray-400"
                >
                  {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                </button>
              </div>
              {editingUser && (
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  Enter a new password only if you want to change it
                </p>
              )}
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Role *
              </label>
              <select
                value={newUser.role}
                onChange={(e) => setNewUser({ ...newUser, role: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-800 dark:text-white"
              >
                <option value="user">User</option>
                <option value="accounts">Accounts</option>
                <option value="admin">Admin</option>
              </select>
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
              Permissions (Granular Control)
            </label>
            <div className="space-y-2 max-h-96 overflow-y-auto border border-gray-200 dark:border-gray-600 rounded-lg p-4">
              {permissionsList.map((perm) => {
                const currentPerm = newUser.permissions[perm.key as keyof typeof newUser.permissions];
                const isExpanded = expandedModules[perm.key];
                
                return (
                  <div key={perm.key} className="border border-gray-200 dark:border-gray-600 rounded-lg p-3">
                    <div className="flex items-center justify-between">
                      <label className="flex items-center space-x-2 cursor-pointer flex-1">
                        <input
                          type="checkbox"
                          checked={getModuleAccess(currentPerm)}
                          onChange={() => toggleModuleAccess(perm.key, true)}
                          className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                        />
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">{perm.label}</span>
                      </label>
                      
                      {perm.hasActions && (
                        <button
                          type="button"
                          onClick={() => toggleModuleExpand(perm.key)}
                          className="p-1 hover:bg-gray-100 dark:hover:bg-gray-600 rounded"
                        >
                          {isExpanded ? (
                            <ChevronUp className="h-4 w-4 text-gray-600 dark:text-gray-400" />
                          ) : (
                            <ChevronDown className="h-4 w-4 text-gray-600 dark:text-gray-400" />
                          )}
                        </button>
                      )}
                    </div>
                    
                    {perm.hasActions && isExpanded && getModuleAccess(currentPerm) && (
                      <div className="mt-3 ml-6 grid grid-cols-2 gap-2 border-l-2 border-blue-200 dark:border-blue-700 pl-4">
                        {actionsList.map((action) => (
                          <label key={action.key} className="flex items-center space-x-2 cursor-pointer">
                            <input
                              type="checkbox"
                              checked={getActionValue(currentPerm, action.key)}
                              onChange={() => toggleAction(perm.key, action.key, true)}
                              className="h-3 w-3 text-green-600 focus:ring-green-500 border-gray-300 rounded"
                            />
                            <span className="text-xs text-gray-600 dark:text-gray-400">
                              {action.icon} {action.label}
                            </span>
                          </label>
                        ))}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </div>

          <div className="flex justify-end">
            <button
              onClick={handleCreateUser}
              disabled={loading}
              className="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors disabled:opacity-50"
            >
              {loading ? (editingUser ? 'Updating...' : 'Creating...') : (editingUser ? 'Update User' : 'Create User')}
            </button>
          </div>
        </div>
      )}

      {loading && !isCreating && (
        <div className="text-center py-8">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
          <p className="mt-4 text-gray-600 dark:text-gray-400">Loading users...</p>
        </div>
      )}

      {!loading && users.length === 0 && !isCreating && (
        <div className="text-center py-8">
          <p className="text-gray-600 dark:text-gray-400">No users found. Create your first user!</p>
        </div>
      )}

      {!loading && users.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                  Email
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                  Role
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                  Permissions
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
              {users.map((user) => (
                <tr key={user.id}>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className="text-sm text-gray-900 dark:text-white">{user.email}</span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                      {user.role}
                    </span>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex flex-wrap gap-1">
                      {user.permissions && Object.keys(user.permissions).length > 0 ? (
                        Object.entries(user.permissions).map(
                          ([key, value]) =>
                            value && (
                              <span
                                key={key}
                                className="px-2 py-1 text-xs bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200 rounded"
                              >
                                {permissionsList.find((p) => p.key === key)?.label || key}
                              </span>
                            )
                        )
                      ) : (
                        <span className="px-2 py-1 text-xs bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400 rounded italic">
                          No permissions assigned
                        </span>
                      )}
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                    <button
                      onClick={() => startEdit(user)}
                      className="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300"
                    >
                      <Edit2 className="h-5 w-5" />
                    </button>
                    <button
                      onClick={() => handleDeleteUser(user.id)}
                      className="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                    >
                      <Trash2 className="h-5 w-5" />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

export default UserManagement;
